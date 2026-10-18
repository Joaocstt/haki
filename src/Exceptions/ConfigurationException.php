<?php

declare(strict_types=1);

namespace Haki\Exceptions;

/**
 * Configuração ausente ou inválida (segredo JWT curto, chave RSA inválida, extensão faltando...).
 */
final class ConfigurationException extends HakiException
{
}
