import { useEffect, useState } from 'react';
import { jsonPostHeaders } from '@/lib/csrf';
import { fileSize } from '../form/dropzone';
import { MAX_PREVIEW_BYTES, PreviewPanel } from './preview-panel';
import type { PreviewPayload } from './preview-panel';

/**
 * What is actually inside a file.
 *
 * Uploading a credential blind is how the wrong key ends up in the vault under
 * the right name, and the mistake is invisible afterwards: the contents are
 * encrypted at rest, so the only way to check used to be downloading a
 * plaintext copy onto your disk for the sake of a glance.
 *
 * Two sources, one panel. `FilePreviewPanel` reads a File the browser already
 * holds, so nothing is uploaded and nothing is audited. `StoredFilePreviewPanel`
 * asks the server for a bounded look at an item already in the vault, which
 * decrypts, which makes it a reveal: owner-only and audited exactly like a
 * download, because the plaintext leaves the server either way.
 */

/** Extensions the browser gives no MIME type for, but that are plain text. */
const TEXT_EXTENSIONS = [
    '.env',
    '.pem',
    '.key',
    '.crt',
    '.cer',
    '.pub',
    '.txt',
    '.md',
    '.json',
    '.yml',
    '.yaml',
    '.toml',
    '.ini',
    '.conf',
    '.cfg',
    '.csv',
    '.log',
    '.sql',
    '.sh',
    '.ts',
    '.js',
    '.php',
];

function localKind(file: File): 'text' | 'image' | 'binary' {
    if (file.type.startsWith('image/')) {
        return 'image';
    }

    if (
        file.type.startsWith('text/') ||
        /^application\/(json|xml|javascript|x-yaml|x-sh|x-pem-file)$/.test(
            file.type,
        )
    ) {
        return 'text';
    }

    // `.env`, `.pem` and other dotfiles arrive with an empty type: the name is
    // the only clue there is.
    const name = file.name.toLowerCase();

    if (
        !file.type &&
        (name.startsWith('.env') ||
            !name.includes('.') ||
            TEXT_EXTENSIONS.some((extension) => name.endsWith(extension)))
    ) {
        return 'text';
    }

    return 'binary';
}

/** The initial state for a local file: only text needs an asynchronous read. */
function initialPayload(file: File): PreviewPayload | null {
    const kind = localKind(file);

    if (kind === 'image') {
        return { kind: 'image', src: URL.createObjectURL(file) };
    }

    if (kind === 'binary') {
        return { kind: 'binary', reason: 'not-text' };
    }

    return null;
}

function LocalPanel({ file, className }: { file: File; className?: string }) {
    const [payload, setPayload] = useState<PreviewPayload | null>(() =>
        initialPayload(file),
    );

    // The blob outlives the component unless it is handed back, and this one
    // holds a secret.
    useEffect(() => {
        if (payload?.kind !== 'image') {
            return;
        }

        const { src } = payload;

        return () => URL.revokeObjectURL(src);
    }, [payload]);

    useEffect(() => {
        if (localKind(file) !== 'text') {
            return;
        }

        let live = true;

        file.slice(0, MAX_PREVIEW_BYTES)
            .text()
            .then(
                (contents) =>
                    live &&
                    setPayload({
                        kind: 'text',
                        contents,
                        truncated: file.size > MAX_PREVIEW_BYTES,
                    }),
            )
            .catch(
                () =>
                    live &&
                    setPayload({
                        kind: 'failed',
                        message: 'That file could not be read.',
                    }),
            );

        return () => {
            live = false;
        };
    }, [file]);

    return (
        <PreviewPanel
            name={file.name}
            size={fileSize(file.size)}
            payload={payload}
            className={className}
        />
    );
}

/**
 * A file the browser is holding, not yet uploaded. Mounted only while it is
 * open, and keyed to the file, so a preview can never belong to the file
 * chosen before it and the object URL dies with the panel.
 */
export function FilePreviewPanel({
    file,
    className,
}: {
    file: File;
    className?: string;
}) {
    return (
        <LocalPanel
            key={`${file.name}:${file.size}:${file.lastModified}`}
            file={file}
            className={className}
        />
    );
}

/**
 * A file already in the personal vault. Mounted only while it is open, so the
 * plaintext is fetched when you ask and dropped the moment you close it.
 */
export function StoredFilePreviewPanel({
    itemId,
    name,
    className,
}: {
    itemId: number;
    name: string;
    className?: string;
}) {
    const [payload, setPayload] = useState<PreviewPayload | null>(null);

    useEffect(() => {
        let live = true;

        fetch(`/personal/items/${itemId}/preview`, {
            method: 'POST',
            headers: jsonPostHeaders(),
            credentials: 'same-origin',
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(response),
            )
            .then((body) => {
                if (!live) {
                    return;
                }

                setPayload(
                    body.kind === 'image'
                        ? { kind: 'image', src: body.dataUri }
                        : body,
                );
            })
            .catch(() => {
                if (live) {
                    setPayload({
                        kind: 'failed',
                        message: 'That file could not be read.',
                    });
                }
            });

        return () => {
            live = false;
        };
    }, [itemId]);

    return <PreviewPanel name={name} payload={payload} className={className} />;
}
