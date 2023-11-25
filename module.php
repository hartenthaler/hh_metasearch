<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2023 webtrees development team
 *                    <http://webtrees.net>
 *
 *
 * MetaSearch (webtrees custom module):
 * Copyright (C) 2023 Hermann Hartenthaler
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * 
 * 
 * MetaSearch
 *
 * A weebtrees(https://webtrees.net) 2.1 custom module to support MetaSuche at https://meta.genealogy.net/.
 * see https://wiki.genealogy.net/Metasuche/neue_Schnittstelle
 * 
 */
 

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\MetaSearch;

require __DIR__ . '/MetaSearch.php';

return new MetaSearch();
