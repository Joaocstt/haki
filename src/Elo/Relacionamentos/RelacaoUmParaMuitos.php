<?php

declare(strict_types=1);

namespace Elo\Relacionamentos;

use Elo\Construtor;
use Elo\Modelo;

/**
 * $temMuitos: o alvo guarda a chave do pai. Ex.: Usuario temMuitos Processo (processos.usuario_id).
 */
final class RelacaoUmParaMuitos extends Relacao
{
    /**
     * @return list<Modelo>
     */
    public function obter(): array
    {
        if ($this->idDoPai() === null) {
            return [];
        }

        return $this->consulta()->obter();
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function criar(array $dados): Modelo
    {
        return $this->criarNoAlvo($dados);
    }

    protected function montarConsulta(): Construtor
    {
        return $this->filtrarPorPai(Construtor::para($this->classeAlvo), $this->fkNoAlvo());
    }
}
