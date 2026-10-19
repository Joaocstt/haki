<?php

declare(strict_types=1);

namespace Elo\Relacionamentos;

use Elo\Construtor;
use Elo\Modelo;
use Elo\Suporte\Pluralizador;

/**
 * $pertenceA: a chave está na própria tabela. Ex.: Usuario pertenceA Setor (usuarios.setor_id).
 */
final class RelacaoPertenceA extends Relacao
{
    public function obter(): ?Modelo
    {
        if ($this->valorLocal() === null) {
            return null;
        }

        $modelo = $this->consulta()->primeiro();

        return $modelo instanceof Modelo ? $modelo : null;
    }

    protected function montarConsulta(): Construtor
    {
        $chave = $this->alvo()->nomeChavePrimaria();
        $consulta = Construtor::para($this->classeAlvo);
        $valor = $this->valorLocal();

        return $valor === null ? $consulta->ondeEm($chave, []) : $consulta->onde($chave, '=', $valor);
    }

    private function valorLocal(): mixed
    {
        return $this->pai->{$this->chaveLocal()};
    }

    private function chaveLocal(): string
    {
        return $this->opcoes['fk'] ?? Pluralizador::snake($this->nome) . '_id';
    }
}
