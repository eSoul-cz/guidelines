<?php

declare(strict_types=1);

namespace Esoul\Guidelines\Source;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Esoul\Guidelines\GuidelinesException;
use Esoul\Guidelines\Layer\LayerId;
use JsonException;
use UnexpectedValueException;

/** `guidelines/source.json` of a guideline source: its name, a description and the installer versions it needs. */
final readonly class SourceManifest
{
    public const string FILE = 'source.json';

    public function __construct(
        public string $name,
        public string $description,
        /** Composer-style constraint on the installer version, e.g. `^0.7`. */
        public string $installer,
    ) {
    }

    /**
     * @param string $origin what to name in messages (a URL or a path)
     */
    public static function read(string $path, string $origin): self {
        if ( ! is_file($path)) {
            throw new GuidelinesException(sprintf('%s has no guidelines/%s. A guideline source needs it (name, description, installer).', $origin, self::FILE));
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GuidelinesException(sprintf('guidelines/%s of %s is not valid JSON: %s', self::FILE, $origin, $e->getMessage()), 0, $e);
        }
        $where = sprintf('guidelines/%s of %s', self::FILE, $origin);
        if ( ! is_array($data) || array_is_list($data)) {
            throw new GuidelinesException(sprintf('%s must contain a JSON object with "name", "description" and "installer".', $where));
        }

        $unknown = array_diff(array_map(strval(...), array_keys($data)), ['name', 'description', 'installer']);
        if ($unknown !== []) {
            throw new GuidelinesException(sprintf('%s: unknown key "%s" (allowed: name, description, installer).', $where, implode('", "', $unknown)));
        }
        foreach (['name', 'description', 'installer'] as $key) {
            if ( ! is_string($data[$key] ?? null) || trim($data[$key]) === '') {
                throw new GuidelinesException(sprintf('%s: "%s" must be a non-empty string.', $where, $key));
            }
        }
        /** @var array{name: string, description: string, installer: string} $data */
        if ( ! LayerId::isValidSource($data['name'])) {
            throw new GuidelinesException(sprintf('%s: "name" must be lowercase letters, digits and hyphens, starting with a letter or digit.', $where));
        }
        try {
            new VersionParser()->parseConstraints($data['installer']);
        } catch (UnexpectedValueException $e) {
            throw new GuidelinesException(sprintf('%s: "installer" is not a valid version constraint: %s', $where, $e->getMessage()), 0, $e);
        }

        return new self($data['name'], trim($data['description']), trim($data['installer']));
    }

    /** The config key of a source must be the name its `source.json` gives, so `acme:...` means the same in every project. */
    public function assertNamed(string $key, string $origin): void {
        if ($this->name !== $key) {
            throw new GuidelinesException(sprintf('The source key "%s" in esoul-guidelines.json does not match the name "%s" in guidelines/%s of %s. Use "%s" as the key and in the layer ids.', $key, $this->name, self::FILE, $origin, $this->name));
        }
    }

    /**
     * @throws GuidelinesException when the constraint excludes the running installer; a development build always passes
     */
    public function assertCompatible(string $installerVersion): void {
        if ( ! self::accepts($this->installer, $installerVersion)) {
            throw new GuidelinesException(sprintf('The source "%s" needs esoul-guidelines "%s" (installer in its source.json), but this is %s. Update the installer, or pin the source to a ref that supports it.', $this->name, $this->installer, $installerVersion));
        }
    }

    public static function accepts(string $constraint, string $installerVersion): bool {
        try {
            $normalized = new VersionParser()->normalize($installerVersion);
        } catch (UnexpectedValueException) {
            return true; // `dev`, a bare commit description: no release to compare
        }
        if (VersionParser::parseStability($installerVersion) === 'dev') {
            return true;
        }

        return Semver::satisfies($normalized, $constraint);
    }
}
