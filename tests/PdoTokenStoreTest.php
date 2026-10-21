<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\Storage\PdoTokenStore;
use Haki\Auth\Storage\RefreshTokenRecord;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Roda o contrato do TokenStore sobre PDO. O SQLite em memória faz o papel do banco compartilhado.
 */
final class PdoTokenStoreTest extends TestCase
{
    private PdoTokenStore $store;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->store = new PdoTokenStore($pdo);
        $this->store->criarTabelas();
        $this->store->criarTabelas(); // segunda chamada não pode quebrar nem apagar dados
    }

    private function refresh(string $hash, string $family = 'f1'): RefreshTokenRecord
    {
        return new RefreshTokenRecord($hash, '7', $family, ['role' => 'admin'], 1000, 2000);
    }

    public function testRefreshTokenSoPodeSerConsumidoUmaVez(): void
    {
        $this->store->saveRefreshToken($this->refresh('h1'));

        self::assertTrue($this->store->consumeRefreshToken('h1', 1100));
        self::assertFalse($this->store->consumeRefreshToken('h1', 1101));

        $encontrado = $this->store->findRefreshToken('h1');
        self::assertSame(1100, $encontrado->usedAt);
        self::assertSame(['role' => 'admin'], $encontrado->claims);
    }

    public function testRevogacaoDeFamiliaBloqueiaOConsumo(): void
    {
        $this->store->saveRefreshToken($this->refresh('h2', 'fam'));
        $this->store->revokeFamily('fam', 1050);

        self::assertFalse($this->store->consumeRefreshToken('h2', 1100));
        self::assertSame(1050, $this->store->findRefreshToken('h2')->revokedAt);
    }

    public function testRevokeUserGuardaSempreOMaiorValor(): void
    {
        $this->store->revokeUser('7', 500);
        $this->store->revokeUser('7', 300);
        self::assertSame(500, $this->store->userRevokedBefore('7'));

        $this->store->revokeUser('7', 900);
        self::assertSame(900, $this->store->userRevokedBefore('7'));
        self::assertNull($this->store->userRevokedBefore('outro'));
    }

    public function testAccessTokenRevogadoEhDetectado(): void
    {
        self::assertFalse($this->store->isAccessTokenRevoked('jti-1'));

        $this->store->revokeAccessToken('jti-1', 2000);
        $this->store->revokeAccessToken('jti-1', 2000); // repetir não quebra

        self::assertTrue($this->store->isAccessTokenRevoked('jti-1'));
    }

    public function testPurgeRemoveSoOsVencidos(): void
    {
        $this->store->saveRefreshToken($this->refresh('velho'));
        $this->store->saveRefreshToken(new RefreshTokenRecord('novo', '7', 'f2', [], 1000, 9000));
        $this->store->revokeAccessToken('antigo', 500);

        $removidos = $this->store->purgeExpired(3000);

        self::assertSame(2, $removidos);
        self::assertNull($this->store->findRefreshToken('velho'));
        self::assertNotNull($this->store->findRefreshToken('novo'));
    }
}
