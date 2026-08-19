import { z } from 'zod';

/**
 * Client-side rules for the organization's shared vault.
 *
 * One for one with `app/Http/Requests/SharedVault/*`; the Form Request runs
 * anyway and is the truth. When a rule changes here it
 * changes there in the same commit.
 */

/** SharedSecretRequest caps a name at 120. */
const name = z
    .string()
    .trim()
    .min(1, 'Give it a name.')
    .max(120, 'Keep it under 120 characters.');

const description = z
    .string()
    .trim()
    .max(1000, 'Keep it under 1000 characters.');

/** The select hands back a string; empty means ungrouped. */
const shared_group_id = z.string();

/** SharedSecretRequest allows 10240 KB, like the personal vault. */
export const SHARED_FILE_MAX_KB = 10240;

export const sharedSecretSchema = z.object({
    name,
    value: z
        .string()
        .min(1, 'A value is required.')
        .max(20000, 'That value is too long to store.'),
    description,
    shared_group_id,
});

export const sharedFileSchema = z.object({
    // Size and type are the dropzone's job; this is the "you have not chosen
    // one yet" rule, and it is the only one zod can see.
    file: z.custom<File>((value) => value instanceof File, {
        message: 'Choose a file to upload.',
    }),
    // A file may be left unnamed - the server falls back to the original
    // filename - so this is the one optional name in the vault.
    name: z.string().trim().max(120, 'Keep it under 120 characters.'),
    description,
    shared_group_id,
});

/**
 * Editing an item. A file keeps its contents, and a secret left blank keeps
 * the value already stored, so `value` is never required here.
 */
export const sharedItemSchema = z.object({
    name,
    value: z
        .string()
        .max(20000, 'That value is too long to store.')
        .optional(),
    description,
    shared_group_id,
});

/** NamedResourceRequest caps a group name at 60, not 120. */
export const sharedGroupSchema = z.object({
    name: z
        .string()
        .trim()
        .min(1, 'Give the group a name.')
        .max(60, 'Keep it under 60 characters.'),
    description: description.optional(),
});

/** RevealSharedSecretRequest: exactly four digits, always. */
export const sharedPinSchema = z.object({
    pin: z
        .string()
        .regex(/^\d{4}$/, 'Your PIN is four digits.'),
});

export type SharedSecretValues = z.infer<typeof sharedSecretSchema>;
export type SharedFileValues = z.infer<typeof sharedFileSchema>;
export type SharedItemValues = z.infer<typeof sharedItemSchema>;
export type SharedGroupValues = z.infer<typeof sharedGroupSchema>;
