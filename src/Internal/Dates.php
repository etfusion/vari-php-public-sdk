<?php

declare(strict_types=1);

namespace ETFusion\VARI\Internal;

/**
 * @internal
 */
final class Dates
{
    public static function parse(string $value): \DateTimeImmutable
    {
        // The API may send nanoseconds; PHP keeps at most microseconds.
        $value = (string) preg_replace('/(\.\d{6})\d+/', '$1', $value);

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new \UnexpectedValueException(sprintf('Unparseable date "%s" in API response.', $value), 0, $e);
        }
    }

    public static function parseOptional(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? self::parse($value) : null;
    }

    public static function format(\DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
