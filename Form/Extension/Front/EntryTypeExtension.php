<?php

namespace Plugin\BlastmailSync\Form\Extension\Front;

use Eccube\Entity\Customer;
use Eccube\Form\Type\Front\EntryType;
use Plugin\BlastmailSync\Service\CustomerSource;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * 会員登録・マイページ会員情報編集にメール受信可否を追加する。
 *
 * 標準の EC-CUBE にはメールマガジンの同意を取る仕組みが無いため、本プラグインで用意する。
 * 描画は EC-CUBE の `eccube_form_options.auto_render` に任せる（テンプレートを書き換えないので
 * テーマを差し替えても壊れない。公式のメルマガ管理プラグインと同じ方式）。
 * 新規登録時は未チェック（明示的に同意を取る）、既存会員は現在値を表示する。
 */
class EntryTypeExtension extends AbstractTypeExtension
{
    public function __construct(private CustomerSource $source)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($this->source->hasExternalOptInField()) {
            return;
        }
        $builder->add('blastmailOptIn', CheckboxType::class, [
            'required' => false,
            'label' => 'メールマガジン',
            'eccube_form_options' => [
                'auto_render' => true,
                'form_theme' => '@BlastmailSync/entry_optin_theme.twig',
            ],
        ]);
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $Customer = $event->getData();
            // 新規登録は未チェックから始める（既存会員の編集では現在値のまま）
            if ($Customer instanceof Customer && $Customer->getId() === null) {
                $Customer->setBlastmailOptIn(false);
            }
        });
    }

    public static function getExtendedTypes(): iterable
    {
        return [EntryType::class];
    }
}
