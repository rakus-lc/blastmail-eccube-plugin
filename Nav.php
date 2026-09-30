<?php

namespace Plugin\BlastmailSync;

use Eccube\Common\EccubeNav;

class Nav implements EccubeNav
{
    public static function getNav(): array
    {
        return [
            'customer' => [
                'children' => [
                    'blastmail_sync' => [
                        'name' => 'blastmail_sync.admin.nav.index',
                        'url' => 'blastmail_sync_admin_index',
                    ],
                    'blastmail_sync_config' => [
                        'name' => 'blastmail_sync.admin.nav.config',
                        'url' => 'blastmail_sync_admin_config',
                    ],
                ],
            ],
        ];
    }
}
