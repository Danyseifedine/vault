import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    AvatarStack,
    EnvBlock,
    Kbd,
    MaskedValue,
    PinInput,
    RevealDialog,
    SecretInput,
    SensitivityBadge,
    SensitivityPicker,
    TagChip,
} from '@/components/vault';
import type { Sensitivity } from '@/components/vault';
import { Field, Panel, Section } from './parts';

/** Buttons, sensitivity, and everything that touches a secret value. */
export function InputsSections() {
    const [sensitivity, setSensitivity] = useState<Sensitivity>('critical');
    const [secret, setSecret] = useState('sk_demo_EXAMPLE_not_a_real_key');
    const [pin, setPin] = useState('');
    const [revealOpen, setRevealOpen] = useState(false);
    const [revealed, setRevealed] = useState<string>();

    return (
        <>
            <Section title="Buttons">
                <Panel label="Variants">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button size="sm">Primary</Button>
                        <Button size="sm" variant="outline">
                            Secondary
                        </Button>
                        <Button size="sm" variant="ghost">
                            Ghost
                        </Button>
                        <Button size="sm" variant="destructive">
                            Delete
                        </Button>
                        <Button size="sm" disabled>
                            Disabled
                        </Button>
                    </div>
                </Panel>
                <Panel label="Sensitivity picker">
                    <SensitivityPicker
                        value={sensitivity}
                        onChange={setSensitivity}
                    />
                </Panel>
            </Section>

            <Section title="Secret inputs">
                <Panel>
                    <Field label="Secret value">
                        <SecretInput value={secret} onChange={setSecret} />
                    </Field>
                    <Field label="Masked value - reveal goes through the server">
                        <MaskedValue
                            level="critical"
                            revealLabel="PIN + pw"
                            value={revealed}
                            onReveal={() => setRevealOpen(true)}
                        />
                    </Field>
                    <Field label="Reveal PIN">
                        <PinInput value={pin} onChange={setPin} />
                    </Field>
                </Panel>
                <Panel>
                    <Field label=".env block">
                        <EnvBlock
                            title="prod / core-api"
                            lines={[
                                'DATABASE_URL=••••••••',
                                'REDIS_URL=••••••••',
                                'LOG_LEVEL=info',
                            ]}
                        />
                    </Field>
                    <Field label="Badges">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <SensitivityBadge level="critical" />
                            <SensitivityBadge level="sensitive" />
                            <SensitivityBadge level="public" />
                            <TagChip label="rotate-soon" />
                        </div>
                    </Field>
                    <Field label="Avatars & kbd">
                        <div className="flex items-center gap-3">
                            <AvatarStack
                                names={[
                                    'Sami K',
                                    'Nour R',
                                    'Omar T',
                                    'Lea A',
                                    'Dany S',
                                    'Rita M',
                                    'Ziad H',
                                ]}
                            />
                            <Kbd keys={['⌘', 'K']} />
                        </div>
                    </Field>
                </Panel>
            </Section>

            <RevealDialog
                open={revealOpen}
                onOpenChange={setRevealOpen}
                variableKey="STRIPE_SECRET_KEY"
                path="acme / lebify / prod / payments"
                level="critical"
                requirement="pin_password"
                onSubmit={() => {
                    setRevealOpen(false);
                    setRevealed('sk_demo_EXAMPLE_not_a_real_key');
                    toast.success('Revealed · logged');
                }}
            />
        </>
    );
}
