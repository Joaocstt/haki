<?php

declare(strict_types=1);

namespace Elo\Suporte;

use JsonSerializable;

/**
 * Resultado de Construtor::paginar(): a página atual e os dados para montar a navegação.
 */
final class Paginador implements JsonSerializable
{
    public readonly int $ultima_pagina;

    /**
     * @param list<object|array<string, mixed>> $dados
     */
    public function __construct(
        public readonly array $dados,
        public readonly int $total,
        public readonly int $por_pagina,
        public readonly int $pagina,
    ) {
        $this->ultima_pagina = max(1, (int) ceil($total / max(1, $por_pagina)));
    }

    /**
     * @return array{dados: list<object|array<string, mixed>>, total: int, pagina: int, por_pagina: int, ultima_pagina: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'dados' => $this->dados,
            'total' => $this->total,
            'pagina' => $this->pagina,
            'por_pagina' => $this->por_pagina,
            'ultima_pagina' => $this->ultima_pagina,
        ];
    }
}
