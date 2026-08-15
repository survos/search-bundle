<?php

/*
 * This file is part of the UxSearch project.
 *
 * (c) Mezcalito (https://www.mezcalito.fr)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Fixtures\Attribute;

use Survos\SearchBundle\Attribute\AsSearch;

#[AsSearch(index: 'search_index')]
class TestClassWithoutName
{
}
