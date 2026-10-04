<?php

namespace App\Domain\Intelligence;

use App\Models\User;
use Illuminate\Validation\ValidationException;

final class CandleTrainingRecovery
{
    /** Keep recovery instructions in the normal 422 message and field error. */
    public static function exception(
        User $trainer,
        array $manifest,
        string $field,
        string $message = 'This history no longer matches the frozen dataset.',
    ): ValidationException {
        if (! $trainer->isOwner()) {
            return ValidationException::withMessages([
                $field => $message.' Choose another dataset or contact the server owner to rebuild it.',
            ]);
        }

        $supportedSchemas = ['core', 'technical', 'full'];
        $schema = $manifest['schema'] ?? null;
        if (! in_array($schema, $supportedSchemas, true)) {
            // knn-build cannot reproduce a custom feature-key selection.
            $configured = config('intelligence.schema', 'core');
            $schema = in_array($configured, $supportedSchemas, true) ? $configured : 'core';
            $message .= "\nThe replacement will use the {$schema} schema because the stored schema is not supported by the rebuild command.";
        }

        // These values come from the loaded manifest, never request parameters.
        $market = implode(' ', array_map(
            static fn (string $value): string => escapeshellarg($value),
            [$manifest['exchange'], $manifest['symbol'], $manifest['period']],
        ));
        $commands = 'php artisan trademinator:build-features '.$market." &&\n"
            .'php artisan trademinator:knn-build '.$market.' --schema='.escapeshellarg($schema);

        return ValidationException::withMessages([
            $field => $message."\n\nRun from the Trademinator Server project directory:\n".$commands
                ."\n\nThis creates a new dataset and rebuilds this market's intelligence model; it does not modify the frozen dataset."
                .' After the commands finish, return to Human training and select the new dataset.'
                .' Unsubmitted labels are not saved when you switch datasets.',
        ]);
    }
}
