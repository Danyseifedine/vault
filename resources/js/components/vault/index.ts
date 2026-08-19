/*
 * The vault component library, one barrel. Pages import from
 * `@/components/vault` and never from a folder inside it - the folders are an
 * internal filing system, not part of the public path.
 *
 * access/   the grant checklist: how access is granted, everywhere
 * form/     the form vocabulary: fields, select, dropzone, PIN, tokens
 * dialogs/  the four dialog shapes: create, rename, destroy, reveal
 * shell/    the application frame: sidebar, header, logo, right-click menu
 * data/     what the vault is about: variables, audit, versions, sensitivity
 * elements/ small standalone atoms: pills, tabs, banners, switches
 */

export * from './access/grant-checklist';

export * from './data/action-trail';
export * from './data/audit-pager';
export * from './data/audit-table';
export * from './data/charts';
export * from './data/env-block';
export * from './data/file-preview';
export * from './data/preview-panel';
export * from './data/masked-value';
export * from './data/sensitivity';
export * from './data/variables-table';
export * from './data/version-history';

export * from './dialogs/create-dialog';
export * from './dialogs/dialog-actions';
export * from './dialogs/dialog-shell';
export * from './dialogs/destructive-dialog';
export * from './dialogs/rename-dialog';
export * from './dialogs/reveal-dialog';

export * from './elements/alert-banner';
export * from './elements/avatar-stack';
export * from './elements/empty-state';
export * from './elements/inline-confirm';
export * from './elements/inline-rename';
export * from './elements/locked';
export * from './elements/policy-switch';
export * from './elements/segmented';
export * from './elements/stat-card';
export * from './elements/tabs';
export * from './elements/tag-pills';

export * from './form/dropzone';
export * from './form/form';
export * from './form/group-field';
export * from './form/pin-input';
export * from './form/secret-input';
export * from './form/select';
export * from './form/token-field';

export * from './shell/context-menu';
export * from './shell/logo';
export * from './shell/app-shell';
export * from './shell/header';
export * from './shell/sidebar';
