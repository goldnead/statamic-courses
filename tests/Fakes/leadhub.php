<?php

/*
 * A stand-in for statamic-leadhub's manager: the two methods courses calls,
 * with the shapes the real ones return (LeadHubManager::findByEmail() and
 * ::contactInSegment()). Loaded only where the real package is absent, which
 * is every test run: leadhub is a `suggest`, not a dev dependency.
 */

namespace Goldnead\Leadhub;

if (! class_exists(LeadHubManager::class)) {
    class LeadHubManager
    {
        /** @var array<string, array<string, mixed>> contacts by email */
        public static array $contacts = [];

        /** @var array<string, list<string>> segment handle => contact uuids */
        public static array $segments = [];

        public static function reset(): void
        {
            self::$contacts = [];
            self::$segments = [];
        }

        public function findByEmail(string $email): ?array
        {
            return self::$contacts[mb_strtolower($email)] ?? null;
        }

        public function contactInSegment(int|string $contactOrId, string $handle): bool
        {
            return in_array((string) $contactOrId, self::$segments[$handle] ?? [], true);
        }
    }
}
