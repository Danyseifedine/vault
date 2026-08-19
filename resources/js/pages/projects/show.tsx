import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    AppShell,
    DestructiveDialog,
    GuardedButton,
    HeaderSearch,
    RevealDialog,
    ShellHeader,
} from '@/components/vault';
import { useReveal } from '@/hooks/use-reveal';
import { useScreen } from '@/hooks/use-screen';
import { AuditScreen } from './partials/audit-screen';
import { DiffScreen } from './partials/diff-screen';
import { EditVariableDialog } from './partials/edit-variable-dialog';
import { requirementFor } from './partials/helpers';
import { HistoryDialog } from './partials/history-dialog';
import { ImportScreen } from './partials/import-screen';
import { MembersScreen } from './partials/members-screen';
import { ProjectOverview } from './partials/overview';
import { EnvTabs, ProjectSidebar } from './partials/project-frame';
import { SettingsScreen } from './partials/settings-screen';
import { NewVariableDialog, SetValueDialog } from './partials/variable-dialogs';
import { VariablesScreen } from './partials/variables-screen';
import type { ProjectPageProps, ProjectVariable } from './types';

const TITLES: Record<string, string> = {
    overview: 'Overview',
    vars: 'Variables',
    diff: 'Diff',
    audit: 'Audit log',
    members: 'Members',
    import: 'Import',
    settings: 'Settings',
};

export default function ProjectShow({
    project,
    environments,
    variables,
    permissions,
    grants,
    auditLog,
    recentActivity,
    admin,
    viewer,
    screen: initialScreen,
}: ProjectPageProps) {
    const [screen, setScreen] = useScreen(initialScreen, 'overview');
    const [env, setEnv] = useState(environments[0]?.id ?? '');
    // What this viewer may do in the environment currently on screen.
    const abilities = viewer.environments[env];
    const [query, setQuery] = useState('');
    const [sensFilter, setSensFilter] = useState('all');
    const [selectedMember, setSelectedMember] = useState<string | null>(null);

    const base = `/orgs/${project.organizationSlug}/projects/${project.slug}`;

    // The revealed value lives here and nowhere else, for as long as the
    // dialog is open.
    const [revealing, setRevealing] = useState<ProjectVariable | null>(null);
    const [revealed, setRevealed] = useState<string | null>(null);
    const {
        reveal,
        reset: resetReveal,
        busy: revealBusy,
        error: revealError,
    } = useReveal();

    const handleReveal = (variable: ProjectVariable) => {
        resetReveal();
        setRevealed(null);
        setRevealing(variable);

        // Nothing to ask for: fetch it straight away and let the dialog open
        // on the value. Asking for a PIN the server will not check is how a
        // setting that says "no PIN" ends up feeling broken.
        if (requirementFor(variable, abilities) === 'none') {
            void submitRevealFor(variable, {});
        }
    };

    const submitRevealFor = async (
        variable: ProjectVariable,
        credentials: { pin?: string; password?: string },
    ) => {
        const outcome = await reveal(
            `${base}/variables/${variable.id}/environments/${env}/reveal`,
            credentials,
        );

        if (outcome.granted) {
            setRevealed(outcome.value);
            toast.success(`${variable.key} revealed · logged`);
        }
    };

    const submitReveal = async (credentials: {
        pin: string;
        password?: string;
    }) => {
        if (revealing) {
            await submitRevealFor(revealing, credentials);
        }
    };

    const closeReveal = () => {
        setRevealing(null);
        setRevealed(null);
        resetReveal();
    };

    const exportEnv = () => {
        // A full navigation, not an Inertia visit: this returns a file.
        window.location.href = `${base}/environments/${env}/export`;
    };

    const [creatingVariable, setCreatingVariable] = useState(false);
    const [settingValue, setSettingValue] = useState<ProjectVariable | null>(
        null,
    );
    const [editingVariable, setEditingVariable] =
        useState<ProjectVariable | null>(null);
    const [viewingHistory, setViewingHistory] =
        useState<ProjectVariable | null>(null);

    // Deleting removes it from EVERY environment, which the dialog says out
    // loud - the server also refuses unless you may delete in all of them.
    const [deletingVariable, setDeletingVariable] =
        useState<ProjectVariable | null>(null);

    const sidebar = (
        <ProjectSidebar
            project={project}
            variables={variables}
            auditLog={auditLog}
            screen={screen}
            onSelect={setScreen}
        />
    );

    return (
        <AppShell sidebar={sidebar}>
            <Head title={project.name} />
            <ShellHeader crumb={project.slug} active={TITLES[screen]}>
                <EnvTabs
                    environments={environments}
                    env={env}
                    onChange={setEnv}
                />
                <div className="flex w-full min-w-0 items-center gap-2 sm:ml-auto sm:w-auto">
                    <HeaderSearch
                        value={query}
                        onChange={setQuery}
                        placeholder="Search keys…"
                    />
                    <GuardedButton
                        allowed={abilities?.import ?? false}
                        reason={`Importing takes variables.import in ${env}`}
                        onClick={() => setScreen('import')}
                        tone="outline"
                        className="shrink-0"
                    >
                        Import .env
                    </GuardedButton>
                    <GuardedButton
                        allowed={abilities?.export ?? false}
                        reason={`Exporting takes variables.export in ${env}`}
                        onClick={exportEnv}
                        className="shrink-0"
                    >
                        Export
                    </GuardedButton>
                </div>
            </ShellHeader>
            <div className="flex-1 overflow-auto">
                {screen === 'overview' && (
                    <ProjectOverview
                        project={project}
                        environments={environments}
                        variables={variables}
                        recentActivity={recentActivity}
                        env={env}
                        onGoDiff={() => setScreen('diff')}
                        onGoAudit={() => setScreen('audit')}
                        onOpenEnv={(e) => {
                            setEnv(e);
                            setScreen('vars');
                        }}
                    />
                )}
                {screen === 'vars' && (
                    <VariablesScreen
                        variables={variables}
                        definedGroups={admin.groups}
                        canManageGroups={admin.can.manageGroups}
                        basePath={base}
                        env={env}
                        abilities={abilities}
                        query={query}
                        sensFilter={sensFilter}
                        onSensFilter={setSensFilter}
                        onReveal={handleReveal}
                        onNew={() => setCreatingVariable(true)}
                        onSetValue={setSettingValue}
                        onEdit={setEditingVariable}
                        onHistory={setViewingHistory}
                        onDelete={setDeletingVariable}
                    />
                )}
                {screen === 'diff' && (
                    <DiffScreen environments={environments} basePath={base} />
                )}
                {screen === 'audit' && (
                    <AuditScreen
                        project={project}
                        auditLog={auditLog}
                        viewer={viewer}
                    />
                )}
                {screen === 'members' && (
                    <MembersScreen
                        admin={admin}
                        permissions={permissions}
                        grants={grants}
                        project={project}
                        selected={selectedMember}
                        onSelect={setSelectedMember}
                        onInvite={() =>
                            router.visit(`/orgs/${project.organizationSlug}`)
                        }
                    />
                )}
                {screen === 'settings' && (
                    <SettingsScreen
                        project={project}
                        admin={admin}
                        basePath={base}
                    />
                )}
                {screen === 'import' && (
                    <ImportScreen
                        env={env}
                        allowed={abilities?.import ?? false}
                        knownVariables={variables}
                        action={`${base}/environments/${env}/import`}
                    />
                )}
            </div>

            <NewVariableDialog
                open={creatingVariable}
                onOpenChange={setCreatingVariable}
                admin={admin}
                action={`${base}/variables`}
            />

            {viewingHistory && (
                <HistoryDialog
                    variable={viewingHistory}
                    env={env}
                    basePath={base}
                    onClose={() => setViewingHistory(null)}
                />
            )}

            {editingVariable && (
                <EditVariableDialog
                    variable={editingVariable}
                    admin={admin}
                    basePath={base}
                    onClose={() => setEditingVariable(null)}
                />
            )}

            {settingValue && (
                <SetValueDialog
                    variable={settingValue}
                    env={env}
                    action={`${base}/variables/${settingValue.id}/environments/${env}/value`}
                    onClose={() => setSettingValue(null)}
                />
            )}

            {deletingVariable && (
                <DestructiveDialog
                    open
                    onOpenChange={(next) => !next && setDeletingVariable(null)}
                    title={`Delete ${deletingVariable.key}?`}
                    description={`Variables are project-wide, so this leaves every environment in ${project.name} at once.`}
                    consequences={[
                        'Its value in every environment is destroyed, along with all version history.',
                        'Apps still reading this key at deploy time will miss it.',
                    ]}
                    action={`${base}/variables/${deletingVariable.id}`}
                    buttonLabel="Delete variable"
                    successMessage={`${deletingVariable.key} deleted · logged`}
                    onDeleted={() => setDeletingVariable(null)}
                />
            )}

            {revealing && (
                <RevealDialog
                    open
                    onOpenChange={(next) => !next && closeReveal()}
                    variableKey={revealing.key}
                    path={`${project.organizationName} / ${project.name} / ${env}`}
                    level={revealing.sensitivity}
                    requirement={requirementFor(revealing, abilities)}
                    onSubmit={submitReveal}
                    submitting={revealBusy}
                    error={revealError}
                    value={revealed ?? undefined}
                />
            )}
        </AppShell>
    );
}
