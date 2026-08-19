import { useState } from 'react';
import {
    Dropzone,
    FileRow,
    ParsePreview,
    fileSize,
    parseEnvPreview,
} from '@/components/vault';
import type { UploadedFile } from '@/components/vault';
import { Panel, Section } from './parts';

const SAMPLE_ENV = `DATABASE_URL=mysql://user:fakepass@localhost:3306/example
REDIS_URL=redis://default:fakepass@localhost:6379
LOG_LEVEL=info`;

/** Bringing an .env into the vault: drop it, or paste it. */
export function UploadSection() {
    const [raw, setRaw] = useState(SAMPLE_ENV);
    const [files, setFiles] = useState<UploadedFile[]>([
        { name: '.env.prod', size: '1.2 KB', percent: 100, state: 'parsed' },
    ]);

    return (
        <Section title="Upload">
            <Panel label="Dropzone">
                <Dropzone
                    title="Drop your .env here"
                    hint=".env, .env.local - up to 64 KB"
                    accept=".env,.env.local,.env.*,text/plain"
                    onFile={(f) =>
                        f &&
                        setFiles((cur) => [
                            ...cur,
                            {
                                name: f.name,
                                size: fileSize(f.size),
                                percent: 100,
                                state: 'parsed',
                            },
                        ])
                    }
                />
                <div className="flex flex-col gap-2">
                    {files.map((f, i) => (
                        <FileRow key={`${f.name}-${i}`} file={f} />
                    ))}
                </div>
            </Panel>
            <Panel label="Paste area with parse preview">
                <textarea
                    value={raw}
                    onChange={(e) => setRaw(e.target.value)}
                    spellCheck={false}
                    className="h-[108px] w-full resize-y rounded-[9px] border border-line-2 bg-panel-2 px-[11px] py-2.5 font-mono text-[11.5px] leading-[1.7] outline-none focus:border-primary"
                />
                <ParsePreview variables={parseEnvPreview(raw)} />
            </Panel>
        </Section>
    );
}
