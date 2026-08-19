import { router } from '@inertiajs/react';
import { FolderClosed } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import {
    Dropzone,
    parseEnvPreview,
    LockedAction,
    SensitivityBadge,
    SensitivityDot,
} from '@/components/vault';
import type { ParsedVariable } from '@/components/vault';
import { importSchema } from '@/lib/validation/vault';
import type { ProjectVariable } from '../types';

export function ImportScreen({
    env,
    allowed,
    knownVariables,
    action,
}: {
    env: string;
    /** Holding variables.import here - otherwise the screen reads but cannot write. */
    allowed: boolean;
    knownVariables: ProjectVariable[];
    /** Where to POST. The server parses again with phpdotenv - this preview is a courtesy. */
    action: string;
}) {
    const [raw, setRaw] = useState('');
    const [busy, setBusy] = useState(false);
    const parsed = parseEnvPreview(raw, knownVariables);

    // The preview, grouped by the classifier's guessed group. Grouped rows come
    // first in map order; the ungrouped rest fall under a nameless section.
    const sections = useMemo(() => groupParsed(parsed), [parsed]);
    // The schema every other form speaks; the server re-parses with
    // phpdotenv regardless.
    const valid = importSchema.safeParse({ contents: raw }).success;

    const submit = () => {
        setBusy(true);

        router.post(
            action,
            { contents: raw },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Imported into ${env} · logged`);
                    setRaw('');
                },
                onError: (errors) =>
                    toast.error(errors.contents ?? 'That import was refused.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <div className="w-full px-4 pt-[18px] pb-14 sm:px-7 sm:pt-[22px]">
            <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                Import .env
            </h1>
            <p className="mb-[18px] text-[13px] text-fg-2">
                Paste a file, or drop one. It parses into variables, grouped and
                labelled - migration takes minutes.
            </p>
            {/*
                Upload is just a faster paste: the file is read HERE, in the
                browser, into the same contents field - nothing goes anywhere
                until the parsed preview is confirmed.
            */}
            <Dropzone
                className="mb-3.5"
                title="Drop a .env or .txt here"
                hint="Any .env or plain-text file, up to 195 KB - it fills the paste box, nothing uploads yet"
                // The browse dialog filters on this; a drag can still carry
                // anything and the server re-validates either way. Kept wide so
                // an oddly-named env file (.env.production, config.txt) is still
                // selectable rather than greyed out.
                accept=".env,.env.local,.env.development,.env.production,.env.staging,.env.test,.txt,.text,text/plain,application/octet-stream"
                // Match the server's 200000-character cap instead of
                // the 64 KB default, and SURFACE a rejection - a file that was
                // silently dropped for being too big is exactly this bug.
                maxKb={195}
                onReject={(reason) => toast.error(reason)}
                onFile={(file) =>
                    file
                        ?.text()
                        .then(setRaw)
                        .catch(() =>
                            toast.error('That file could not be read.'),
                        )
                }
            />

            <div className="grid grid-cols-2 items-start gap-3.5 max-lg:grid-cols-1">
                <div className="overflow-hidden rounded-xl border border-line bg-panel">
                    <div className="border-b border-line px-3.5 py-2.5 font-mono text-[11.5px] text-fg-3">
                        .env - paste here
                    </div>
                    <textarea
                        value={raw}
                        onChange={(e) => setRaw(e.target.value)}
                        spellCheck={false}
                        placeholder={'DATABASE_URL=...\nLOG_LEVEL=info'}
                        className="h-[300px] w-full resize-y border-0 bg-transparent p-3.5 font-mono text-xs leading-[1.7] text-fg outline-none placeholder:text-fg-3"
                    />
                </div>
                <div className="overflow-hidden rounded-xl border border-line bg-panel">
                    <div className="flex items-center gap-2 border-b border-line px-3.5 py-2.5">
                        <span className="text-[12.5px] font-semibold">
                            Parsed
                        </span>
                        <span className="text-[11.5px] text-fg-3">
                            {parsed.length} variables detected
                        </span>
                        {parsed.length > 0 &&
                            (allowed ? (
                                <button
                                    type="button"
                                    onClick={submit}
                                    disabled={busy || !valid}
                                    className="ml-auto h-[26px] cursor-pointer rounded-[7px] border-0 bg-primary px-2.5 text-xs text-primary-foreground transition-[filter] hover:brightness-108 disabled:cursor-progress disabled:opacity-85"
                                >
                                    {busy ? 'Importing…' : `Add to ${env}`}
                                </button>
                            ) : (
                                <LockedAction
                                    className="ml-auto"
                                    reason={`Importing takes variables.import in ${env}`}
                                />
                            ))}
                    </div>
                    {parsed.length === 0 ? (
                        <div className="p-3.5 text-[11.5px] text-fg-3">
                            Paste a .env file to see the parsed variables.
                        </div>
                    ) : (
                        <div className="max-h-[320px] overflow-auto">
                            {sections.map((section) => (
                                <div key={section.name ?? '__ungrouped'}>
                                    {/* A guessed group is a header the newcomers
                                        land under - so "smart grouping" is
                                        visible before a single row is written. */}
                                    {section.name && (
                                        <div className="flex items-center gap-1.5 border-b border-line bg-panel-2 px-3.5 py-1.5">
                                            <FolderClosed
                                                className="size-3 text-fg-3"
                                                strokeWidth={1.75}
                                            />
                                            <span className="text-[11px] font-semibold text-fg-2">
                                                {section.name}
                                            </span>
                                            <span className="text-[10.5px] text-fg-3">
                                                {section.rows.length}
                                            </span>
                                        </div>
                                    )}
                                    {section.rows.map((p) => (
                                        <div
                                            key={p.key}
                                            className="flex items-center gap-2.5 border-b border-line px-3.5 py-2"
                                        >
                                            <SensitivityDot
                                                level={p.sensitivity}
                                                size={6}
                                            />
                                            <span className="truncate font-mono text-[11.5px]">
                                                {p.key}
                                            </span>
                                            <span className="ml-auto truncate font-mono text-[11px] text-fg-3">
                                                {p.masked}
                                            </span>
                                            <SensitivityBadge
                                                level={p.sensitivity}
                                            />
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

/**
 * Buckets the parsed rows by their guessed group, preserving first-seen order.
 * A null group collects into one trailing nameless section, so ungrouped keys
 * still show without pretending to have a home.
 */
function groupParsed(
    parsed: ParsedVariable[],
): { name: string | null; rows: ParsedVariable[] }[] {
    const order: (string | null)[] = [];
    const byGroup = new Map<string | null, ParsedVariable[]>();

    for (const row of parsed) {
        const key = row.group ?? null;

        if (!byGroup.has(key)) {
            byGroup.set(key, []);
            order.push(key);
        }

        byGroup.get(key)!.push(row);
    }

    // Named groups first, the nameless catch-all last.
    return order
        .sort((a, b) => (a === null ? 1 : b === null ? -1 : 0))
        .map((name) => ({ name, rows: byGroup.get(name)! }));
}
