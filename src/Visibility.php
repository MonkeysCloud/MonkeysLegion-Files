<?php
declare(strict_types=1);

namespace MonkeysLegion\Files;

/**
 * MonkeysLegion Framework — Files Package
 *
 * File visibility levels.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum Visibility: string
{
    case Public  = 'public';
    case Private = 'private';
}
