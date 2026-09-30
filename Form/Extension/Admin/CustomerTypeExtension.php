<?php

namespace Plugin\BlastmailSync\Form\Extension\Admin;

use Eccube\Form\Type\Admin\CustomerType;
use Plugin\BlastmailSync\Service\CustomerSource;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * 管理画面の会員編集にメール受信可否のチェックボックスを追加する。
 * 公式のメルマガ管理プラグイン等が受信可否を持っている場合は、そちらを使うので追加しない。
 */
class CustomerTypeExtension extends AbstractTypeExtension
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
            'label' => 'メールマガジンを受信する',
            'eccube_form_options' => ['auto_render' => true],
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        return [CustomerType::class];
    }
}
