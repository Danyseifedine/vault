<?php

namespace App\Actions\Variables;

use App\Models\User;
use App\Models\VariableValue;
use App\Models\VariableValueVersion;
use Illuminate\Validation\ValidationException;

/**
 * Restores an earlier value by writing it forward as a NEW version.
 *
 * History is never rewritten - a rollback is itself a change, and the log
 * shows exactly that.
 */
class RollbackVariableValue
{
    public function __construct(private SetVariableValue $setValue) {}

    public function __invoke(VariableValue $value, VariableValueVersion $target, User $author): VariableValue
    {
        if ($target->variable_value_id !== $value->id) {
            throw ValidationException::withMessages([
                'version' => 'That version belongs to a different variable.',
            ]);
        }

        // A rollback is its own kind of change: "who reverted prod at 3am"
        // must be answerable from the event name alone.
        return ($this->setValue)(
            $value->variable,
            $value->environment,
            $author,
            $target->value,
            updateEvent: 'variable.value-rolled-back',
        );
    }
}
