<?php

declare(strict_types=1);

use Beziworld\Allegro\Data\AccessToken;
use Beziworld\Allegro\Data\Address;
use Beziworld\Allegro\Data\Money;
use Beziworld\Allegro\Enums\FulfillmentStatus;
use Beziworld\Allegro\Exceptions\ApiException;
use Beziworld\Allegro\Support\Query;
use Carbon\CarbonImmutable;

it('builds query strings with repeated keys, dotted names and UTC dates', function (): void {
    $query = Query::build([
        'publication.status' => ['ACTIVE', 'ENDED'],
        'read' => false,
        'skip' => null,
        'status' => FulfillmentStatus::Sent,
        'updatedAt.gte' => CarbonImmutable::parse('2026-07-01 02:30:00', 'Europe/Warsaw'),
        'name' => 'buty zimowe',
    ]);

    expect($query)->toBe('publication.status=ACTIVE&publication.status=ENDED&read=false&status=SENT&updatedAt.gte=2026-07-01T00%3A30%3A00.000Z&name=buty%20zimowe')
        ->and(Query::append('/sale/offers?x=1', ['limit' => 10]))->toBe('/sale/offers?x=1&limit=10')
        ->and(Query::append('/me', []))->toBe('/me');
});

it('keeps money amounts as strings', function (): void {
    expect(Money::of(10)->toArray())->toBe(['amount' => '10.00', 'currency' => 'PLN'])
        ->and(Money::of(19.999, 'CZK')->amount)->toBe('20.00')
        ->and(Money::of('7.5')->amount)->toBe('7.5')
        ->and(Money::fromArray(['amount' => null, 'currency' => 'PLN']))->toBeNull()
        ->and(Money::fromArray(null))->toBeNull()
        ->and((string) Money::fromArray(['amount' => '220.85', 'currency' => 'EUR']))->toBe('220.85 EUR');
});

it('serializes tokens and reports their validity', function (): void {
    CarbonImmutable::setTestNow('2026-11-04 12:00:00');

    $token = AccessToken::fromArray(tokenPayload('a', 'r', 43199));
    $restored = AccessToken::fromArray($token->toArray());

    expect($restored->expiresAt->equalTo($token->expiresAt))->toBeTrue()
        ->and($restored->refreshToken)->toBe('r')
        ->and($token->isValid())->toBeTrue()
        ->and($token->expire()->isValid())->toBeFalse()
        ->and($token->expire()->refreshToken)->toBe('r')
        ->and(AccessToken::fromArray(tokenPayload('a', 'r', 200))->isValid())->toBeFalse();

    CarbonImmutable::setTestNow();
});

it('maps both postal code spellings of Allegro addresses', function (): void {
    expect(Address::fromArray(['street' => 'A 1', 'city' => 'B', 'postCode' => '00-001', 'countryCode' => 'PL'])?->zipCode)->toBe('00-001')
        ->and(Address::fromArray(['zipCode' => '00-002'])?->zipCode)->toBe('00-002')
        ->and(Address::fromArray([]))->toBeNull()
        ->and(Address::fromArray(['firstName' => null, 'lastName' => null])?->fullName())->toBeNull();
});

it('parses error lists defensively', function (): void {
    expect(ApiException::parseErrors(['x', ['code' => 'E', 'message' => null, 'path' => '']]))->toBe([
        ['code' => 'E', 'message' => 'Unknown error', 'details' => null, 'path' => null, 'userMessage' => null],
    ])->and(ApiException::fromResponse([], 500)->getMessage())->toBe('Allegro request failed (HTTP 500).');
});
