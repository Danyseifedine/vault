import { z } from 'zod';

/**
 * Client-side rules for the vault's forms.
 *
 * These mirror the Form Requests one-for-one and exist for UX only - the server
 * re-validates everything. When a rule changes here it
 * changes in the matching Form Request in the same commit.
 */

export const organizationSchema = z.object({
    name: z
        .string()
        .trim()
        .min(1, 'Give the organization a name.')
        .max(120, 'Keep it under 120 characters.'),
});

export const projectSchema = z.object({
    name: z
        .string()
        .trim()
        .min(1, 'Give the project a name.')
        .max(120, 'Keep it under 120 characters.'),
    description: z
        .string()
        .trim()
        .max(1000, 'Keep it under 1000 characters.')
        .optional(),
});

/** Matches StoreVariableRequest: env keys are SCREAMING_SNAKE_CASE. */
export const variableSchema = z.object({
    key: z
        .string()
        .trim()
        .min(1, 'A variable needs a key.')
        .max(120, 'Keep it under 120 characters.')
        .regex(
            /^[A-Z][A-Z0-9_]*$/,
            'Use capitals, numbers and underscores only - for example DATABASE_URL.',
        ),
    description: z.string().trim().max(1000).optional(),
    sensitivity: z.enum(['public', 'sensitive', 'critical']),
    change_safety: z.enum(['safe', 'breaking']),
    // Null is "ungrouped", which is a real answer rather than a missing one.
    group_id: z.number().int().positive().nullable(),
});

export const variableValueSchema = z.object({
    // Empty is legitimate - plenty of keys are deliberately blank.
    value: z.string().max(20000, 'That value is too long to store.'),
});

/** One grant spec: a permission, optionally pinned to a project/environment. */
export const grantRowSchema = z.object({
    permission: z.string().min(1),
    project_id: z.number().int().positive().nullish(),
    environment_id: z.number().int().positive().nullish(),
});

export const inviteSchema = z.object({
    email: z
        .string()
        .trim()
        .min(1, 'Email is required.')
        .email('Enter a valid email.')
        .max(255, 'Keep the email under 255 characters.'),
    grants: z.array(grantRowSchema).max(500),
});

export const importSchema = z.object({
    // 200000 mirrors ImportEnvRequest - a dropped file lands in the same box,
    // so the cap has to live here too or a big paste only fails after upload.
    contents: z
        .string()
        .trim()
        .min(1, 'Paste the contents of an .env file.')
        .max(200000, 'That .env is too large to import.'),
});

/**
 * Groups and environments: a name, 1-60 characters, mirroring
 * NamedResourceRequest. Shared so the "add" row, the inline rename and the
 * group typed inside the variable dialogs all agree with the server and each
 * other (a name that will not save is not a name).
 */
export const namedResourceSchema = z
    .string()
    .trim()
    .min(1, 'A name is required.')
    .max(60, 'Keep it under 60 characters.');

/**
 * A project group: a name only. The optional description is never shown
 * (CreateDialog is opened withDescription off) - it is here so the schema
 * matches the dialog's {name, description} shape.
 */
export const groupSchema = z.object({
    name: namedResourceSchema,
    description: z.string().trim().max(1000).optional(),
});

/** Mirrors IssuePinRequest: four digits, an optional label up to 60. */
export const issuePinSchema = z.object({
    pin: z.string().regex(/^\d{4}$/, 'A reveal PIN is exactly four digits.'),
    label: z.string().trim().max(60, 'Keep the label under 60 characters.'),
});

/** Mirrors UpdateProjectSettingsRequest, bounds included. */
export const projectSecuritySchema = z.object({
    audit_views: z.boolean(),
    pin_max_attempts: z
        .number({ message: 'Attempts must be a number.' })
        .int('Whole attempts only.')
        .min(1, 'At least one attempt.')
        .max(20, 'At most 20 attempts.'),
    pin_lockout_minutes: z
        .number({ message: 'Minutes must be a number.' })
        .int('Whole minutes only.')
        .min(1, 'At least one minute.')
        .max(1440, 'At most a day.'),
});

/** Mirrors StoreTagRequest's name rule; scope ids ride separately. */
export const tagNameSchema = z.object({
    name: z
        .string()
        .trim()
        .min(1, 'Give the tag a name.')
        .max(40, 'Keep it under 40 characters.'),
});

/** The create dialog adds the scope choice the rename never gets to touch. */
export const tagCreateSchema = tagNameSchema.extend({
    target: z.string().min(1, 'Pick a scope.'),
});

/** A reveal PIN is always exactly four digits. */
export const revealSchema = z.object({
    pin: z
        .string()
        .regex(/^\d{4}$/, 'Your PIN is four digits.')
        .optional()
        .or(z.literal('')),
    password: z.string().optional(),
});

export type IssuePinValues = z.infer<typeof issuePinSchema>;
export type ProjectSecurityValues = z.infer<typeof projectSecuritySchema>;
export type TagNameValues = z.infer<typeof tagNameSchema>;
export type TagCreateValues = z.infer<typeof tagCreateSchema>;
export type OrganizationValues = z.infer<typeof organizationSchema>;
export type ProjectValues = z.infer<typeof projectSchema>;
export type VariableValues = z.infer<typeof variableSchema>;
export type VariableValueValues = z.infer<typeof variableValueSchema>;
export type InviteValues = z.infer<typeof inviteSchema>;
export type ImportValues = z.infer<typeof importSchema>;
export type RevealValues = z.infer<typeof revealSchema>;
