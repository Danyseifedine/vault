import { z } from 'zod';

/**
 * Client-side rules for the personal vault.
 *
 * One for one with `app/Http/Requests/Personal/*`; the Form Request runs anyway
 * and is the truth. When a rule changes here it changes
 * there in the same commit.
 */

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
const personal_group_id = z.string();

/** StorePersonalFileRequest allows 10240 KB. */
export const PERSONAL_FILE_MAX_KB = 10240;

export const personalSecretSchema = z.object({
    name,
    value: z
        .string()
        .min(1, 'A value is required.')
        .max(20000, 'That value is too long to store.'),
    personal_group_id,
});

export const personalFileSchema = z.object({
    // Size and type are the dropzone's job; this is the "you have not chosen
    // one yet" rule, and it is the only one zod can see.
    file: z.custom<File>((value) => value instanceof File, {
        message: 'Choose a file to upload.',
    }),
    // A file may be left unnamed - StorePersonalFile falls back to the original
    // filename - so this is the one optional name in the vault.
    name: z.string().trim().max(120, 'Keep it under 120 characters.'),
    description,
    personal_group_id,
});

/**
 * Editing an item. `value` is optional and blank means "keep the stored one",
 * so a rename can never overwrite the secret it was meant to describe - the
 * same rule UpdatePersonalItemRequest enforces on the way in.
 */
export const personalItemSchema = z.object({
    name,
    value: z
        .string()
        .max(20000, 'That value is too long to store.')
        .optional(),
    description,
    personal_group_id,
});

/**
 * StorePersonalGroupRequest is stricter than a project name: 60, not 120.
 * The optional description keeps the shape `CreateDialog` expects; a group
 * form never shows that field.
 */
export const personalGroupSchema = z.object({
    name: z
        .string()
        .trim()
        .min(1, 'Give the group a name.')
        .max(60, 'Keep it under 60 characters.'),
    description: description.optional(),
});

export type PersonalSecretValues = z.infer<typeof personalSecretSchema>;
export type PersonalFileValues = z.infer<typeof personalFileSchema>;
export type PersonalItemValues = z.infer<typeof personalItemSchema>;
export type PersonalGroupValues = z.infer<typeof personalGroupSchema>;
