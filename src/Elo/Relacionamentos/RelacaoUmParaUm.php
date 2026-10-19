<?php

declare(strict_types=1);

namespace Elo\Relacionamentos;

use Elo\Construtor;
use Elo\Modelo;

/**
 * $temUm: o alvo guarda a chave do pai. Ex.: Usuario temUm Perfil (perfis.usuario_id).
 */
final class RelacaoUmParaUm extends Relacao
{
    public function obter(): ?Modelo
    {
        if ($this->idDoPai() === null) {
            return null;
        }

        $modelo = $this->consulta()->primeiro();

        return $modelo instanceof Modelo ? $modelo : null;
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
