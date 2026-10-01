<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use InvalidArgumentException;

final readonly class ActionMetadata
{
    /** Definitions use the deliberately bounded fnlla.action-shape.v1 vocabulary. */
    public function __construct(
        public string $description,
        public array $input,
        public array $output,
        public string $kind = 'command',
        public string $visibility = 'internal',
        public int $version = 1,
        public array $identityDomains = ['application'],
        public bool $resourceRequired = false
    ) {
        if (trim($description) === '' || strlen($description) > 2048
            || !in_array($kind, ['command', 'query'], true)
            || !in_array($visibility, ['internal', 'public'], true) || $version < 1
            || $identityDomains === [] || !array_is_list($identityDomains)) {
            throw new InvalidArgumentException('Invalid action metadata.');
        }
        foreach ($identityDomains as $domain) { self::identifier($domain); }
        if ($kind === 'command' && $identityDomains !== ['application']) {
            throw new InvalidArgumentException('Transactional commands currently require the application identity domain.');
        }
        ActionShape::assertDefinition($input);
        ActionShape::assertDefinition($output);
        if (($input['type'] ?? null) !== 'object' || ($output['type'] ?? null) !== 'object'
            || ($input['hidden'] ?? false) || ($output['hidden'] ?? false)
            || ($input['nullable'] ?? false) || ($output['nullable'] ?? false)) {
            throw new InvalidArgumentException('Action input and output must be non-null object definitions.');
        }
        if ($visibility === 'public') { ActionShape::assertPublicInput($input); }
    }

    public static function identifier(mixed $id): void
    {
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid capability identifier.');
        }
    }
}
