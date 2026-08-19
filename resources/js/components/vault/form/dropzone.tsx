import { FileText, Upload, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import { classifyGroups, classifySensitivity } from '@/lib/env-classify';
import { cn } from '@/lib/utils';
import { SensitivityDot } from '../data/sensitivity';
import type { Sensitivity } from '../data/sensitivity';

const KB = 1024;

export function fileSize(bytes: number) {
    return bytes >= KB * KB
        ? `${(bytes / (KB * KB)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / KB))} KB`;
}

/**
 * Choosing a file: drag onto it, or browse.
 *
 * The browser's own `<input type="file">` draws an operating-system button that
 * cannot be styled, so every upload in the product goes through this instead.
 * It is deliberately controlled: pass the file back in and it shows what is
 * about to be sent, which is the difference between "I picked something" and
 * "I think I picked something".
 *
 * Over the size limit is REPORTED, never silently dropped. The `accept` list
 * only filters the browse dialog - a drag can carry anything - so the server
 * validates the type and the size again regardless.
 */
export function Dropzone({
    onFile,
    file = null,
    title = 'Drop your file here',
    hint,
    accept,
    maxKb = 64,
    error = false,
    onReject,
    children,
    className,
}: {
    onFile: (file: File | null) => void;
    /** The current selection. `null` shows the empty prompt. */
    file?: File | null;
    title?: string;
    /** The line under the title. Defaults to the size limit alone. */
    hint?: string;
    accept?: string;
    maxKb?: number;
    error?: boolean;
    /** Told why a file was refused, so the form can say so. */
    onReject?: (reason: string) => void;
    /**
     * Extra controls for the chosen file, sitting beside Remove - the preview
     * toggle, usually. Anything here belongs to the file, so it lives in the
     * same row rather than floating underneath the box.
     */
    children?: ReactNode;
    className?: string;
}) {
    const [dragging, setDragging] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    const handle = (picked: File | undefined) => {
        if (!picked) {
            return;
        }

        if (picked.size > maxKb * KB) {
            onReject?.(
                `${picked.name} is ${fileSize(picked.size)}, and the limit is ${fileSize(maxKb * KB)}.`,
            );

            return;
        }

        onFile(picked);
    };

    return (
        <div
            onDragOver={(e) => {
                e.preventDefault();
                setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={(e) => {
                e.preventDefault();
                setDragging(false);
                handle(e.dataTransfer.files?.[0]);
            }}
            className={cn(
                'rounded-[10px] border border-dashed transition-colors',
                file ? 'p-3' : 'p-[22px] text-center',
                dragging
                    ? 'border-primary bg-primary-soft'
                    : error
                      ? 'border-crit bg-transparent'
                      : 'border-line-2 bg-transparent',
                className,
            )}
        >
            {file ? (
                <div className="flex items-center gap-3 text-left">
                    <span className="grid size-9 shrink-0 place-items-center rounded-[9px] bg-primary-soft text-primary">
                        <FileText className="size-[18px]" strokeWidth={1.75} />
                    </span>
                    <div className="min-w-0">
                        <div className="truncate text-[12.5px] font-semibold">
                            {file.name}
                        </div>
                        <div className="text-[11px] text-fg-3">
                            {fileSize(file.size)}
                        </div>
                    </div>
                    <div className="ml-auto flex shrink-0 items-center gap-1.5">
                        {children}
                        <button
                            type="button"
                            onClick={() => onFile(null)}
                            aria-label="Remove this file"
                            className="grid size-7 cursor-pointer place-items-center rounded-md border border-line-2 bg-transparent text-fg-3 transition-colors hover:border-crit-soft hover:bg-crit-soft hover:text-crit"
                        >
                            <X className="size-3.5" strokeWidth={2} />
                        </button>
                    </div>
                </div>
            ) : (
                <>
                    <div className="mx-auto mb-2.5 grid size-10 place-items-center rounded-[10px] bg-panel-3 text-fg-2">
                        <Upload className="size-5" strokeWidth={1.6} />
                    </div>
                    <div className="mb-1 text-[13px]">
                        {dragging ? 'Release to upload' : title}
                    </div>
                    <div className="text-[11.5px] text-fg-3">
                        {hint ?? `Up to ${fileSize(maxKb * KB)}`}
                    </div>
                    <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        className="mt-3 h-[30px] cursor-pointer rounded-lg border border-line-2 bg-transparent px-3 text-xs transition-colors hover:bg-panel-2"
                    >
                        Browse files
                    </button>
                </>
            )}
            <input
                ref={inputRef}
                type="file"
                accept={accept}
                className="hidden"
                onChange={(e) => {
                    handle(e.target.files?.[0]);
                    // Re-picking the same file after a Remove must still fire.
                    e.target.value = '';
                }}
            />
        </div>
    );
}

export interface UploadedFile {
    name: string;
    size: string;
    percent: number;
    state: 'uploading' | 'parsed' | 'failed';
}

export function FileRow({
    file,
    className,
}: {
    file: UploadedFile;
    className?: string;
}) {
    const tone =
        file.state === 'parsed'
            ? 'text-pub bg-pub-soft'
            : file.state === 'failed'
              ? 'text-crit bg-crit-soft'
              : 'text-primary bg-primary-soft';
    const bar =
        file.state === 'parsed'
            ? 'bg-pub'
            : file.state === 'failed'
              ? 'bg-crit'
              : 'bg-primary';

    return (
        <div
            className={cn(
                'flex items-center gap-2.5 rounded-[9px] border border-line bg-panel-2 px-[11px] py-[9px]',
                className,
            )}
        >
            <span className="font-mono text-[11.5px]">{file.name}</span>
            <span className="text-[11px] text-fg-3">{file.size}</span>
            <span className="h-1 flex-1 overflow-hidden rounded-full bg-panel-3">
                <span
                    className={cn('block h-full', bar)}
                    style={{ width: `${file.percent}%` }}
                />
            </span>
            <span
                className={cn(
                    'inline-flex w-fit items-center rounded-full px-2 py-0.5 text-[10.5px]',
                    tone,
                )}
            >
                {file.state}
            </span>
        </div>
    );
}

export interface ParsedVariable {
    key: string;
    masked: string;
    sensitivity: Sensitivity;
    /** The guessed group for a NEW key; null when it keeps an existing home. */
    group: string | null;
}

/** Preview of variables detected in a pasted/uploaded .env. */
export function ParsePreview({
    variables,
    className,
}: {
    variables: ParsedVariable[];
    className?: string;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-[9px] border border-line',
                className,
            )}
        >
            <div className="border-b border-line bg-panel-2 px-[11px] py-2 text-[11.5px] text-fg-3">
                {variables.length} variables detected
            </div>
            {variables.map((p) => (
                <div
                    key={p.key}
                    className="flex items-center gap-2 border-b border-line px-[11px] py-[7px] last:border-b-0"
                >
                    <SensitivityDot level={p.sensitivity} size={6} />
                    <span className="font-mono text-[11.5px]">{p.key}</span>
                    <span className="ml-auto font-mono text-[11px] text-fg-3">
                        {p.masked}
                    </span>
                </div>
            ))}
        </div>
    );
}

/**
 * Client-side .env parse used only for the import PREVIEW - the server
 * re-parses authoritatively with the same EnvClassifier. `known` overrides the
 * guess with a variable's real sensitivity, so the preview and the vault never
 * disagree; a key the project already defines also keeps its existing group, so
 * no group is guessed for it here.
 */
export function parseEnvPreview(
    raw: string,
    known: { key: string; sensitivity: Sensitivity }[] = [],
): ParsedVariable[] {
    const rows = raw
        .split('\n')
        .map((l) => l.trim())
        .filter((l) => l && !l.startsWith('#') && l.includes('='))
        .map((l) => ({
            key: l.slice(0, l.indexOf('=')).trim(),
            val: l.slice(l.indexOf('=') + 1).trim(),
        }));

    const groups = classifyGroups(rows.map((r) => r.key));

    return rows.map(({ key, val }) => {
        const match = known.find((v) => v.key === key);
        const sensitivity: Sensitivity =
            match?.sensitivity ?? classifySensitivity(key, val);

        return {
            key,
            sensitivity,
            // Existing keys keep their home; only newcomers get a guess.
            group: match ? null : (groups[key] ?? null),
            masked: sensitivity === 'public' ? val : '••••••••',
        };
    });
}
