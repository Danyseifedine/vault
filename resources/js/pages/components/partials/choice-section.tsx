import { useState } from 'react';
import {
    PolicySwitchRow,
    Segmented,
    Select,
    TagPills,
    TokenField,
} from '@/components/vault';
import { Field, Panel, Section } from './parts';

/** Every way the product offers a choice. */
export function ChoiceSection() {
    const [group, setGroup] = useState('database');
    const [app, setApp] = useState('core-api');
    const [tokens, setTokens] = useState(['core-api', 'payments-api']);
    const [tags, setTags] = useState(['rotate-soon']);
    const [env, setEnv] = useState('prod');
    const [switches, setSwitches] = useState({
        pin: true,
        pw: true,
        audit: false,
    });

    return (
        <Section title="Choice">
            <Panel>
                <Field
                    label="Select - single choice, filterable"
                    hint="Picking one closes the list."
                >
                    <Select
                        mono
                        size="lg"
                        value={group}
                        onChange={setGroup}
                        searchPlaceholder="Filter groups…"
                        emptyLabel="No groups match."
                        options={[
                            { value: 'database', meta: '3 vars' },
                            { value: 'redis', meta: '2 vars' },
                            { value: 'mail', meta: '2 vars' },
                            { value: 'auth', meta: '3 vars' },
                            { value: 'payments', meta: '3 vars' },
                            { value: 'observability', meta: '2 vars' },
                        ].map((o) => ({ ...o, label: o.value }))}
                    />
                </Field>
                <Field label="Combobox - searchable">
                    <Select
                        mono
                        searchable
                        size="lg"
                        value={app}
                        onChange={setApp}
                        searchPlaceholder="Filter apps…"
                        emptyLabel="No apps match."
                        options={[
                            'core-api',
                            'payments-api',
                            'admin-web',
                            'mobile-ios',
                            'worker-queue',
                        ].map((value) => ({ value, label: value }))}
                    />
                </Field>
                <Field
                    label="App assignments - token field"
                    hint="Enter to add, Backspace to remove the last."
                >
                    <TokenField
                        tokens={tokens}
                        onChange={setTokens}
                        placeholder="add app…"
                    />
                </Field>
                <Field label="Free tags">
                    <TagPills
                        options={[
                            'legacy',
                            'rotate-soon',
                            'third-party',
                            'generated',
                        ]}
                        selected={tags}
                        onChange={setTags}
                    />
                </Field>
                <Field label="Environment">
                    <Segmented
                        options={[
                            { value: 'dev', label: 'dev' },
                            { value: 'staging', label: 'staging' },
                            { value: 'prod', label: 'prod' },
                        ]}
                        value={env}
                        onChange={setEnv}
                    />
                </Field>
            </Panel>
            <Panel label="Policy switches">
                <PolicySwitchRow
                    label="Require PIN for reveals"
                    hint="Sensitive and critical values."
                    checked={switches.pin}
                    onChange={(v) => setSwitches((s) => ({ ...s, pin: v }))}
                />
                <PolicySwitchRow
                    label="Password for critical"
                    hint="Re-enter the account password."
                    checked={switches.pw}
                    onChange={(v) => setSwitches((s) => ({ ...s, pw: v }))}
                />
                <PolicySwitchRow
                    label="Audit view actions"
                    hint="Changes are always audited regardless."
                    checked={switches.audit}
                    onChange={(v) => setSwitches((s) => ({ ...s, audit: v }))}
                />
            </Panel>
        </Section>
    );
}
