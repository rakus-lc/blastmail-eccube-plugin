<?php

namespace Plugin\BlastmailSync;

use Eccube\Plugin\AbstractPluginManager;
use Plugin\BlastmailSync\Entity\Config;
use Psr\Container\ContainerInterface;

class PluginManager extends AbstractPluginManager
{
    /**
     * 有効化時に設定行（id=1）を用意する。
     */
    public function enable(array $meta, ContainerInterface $container)
    {
        $em = $container->get('doctrine')->getManager();
        if ($em->getRepository(Config::class)->find(1) === null) {
            $em->persist(new Config());
            $em->flush();
        }
    }
}
