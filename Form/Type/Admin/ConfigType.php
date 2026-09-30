<?php

namespace Plugin\BlastmailSync\Form\Type\Admin;

use Plugin\BlastmailSync\Entity\Config;
use Plugin\BlastmailSync\Service\CustomerSource;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

class ConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('apiBaseUrl', TextType::class, [
                'constraints' => [new NotBlank(), new Url(), new Length(['max' => 255])],
            ])
            ->add('username', TextType::class, [
                'required' => false,
                'constraints' => [new Length(['max' => 255])],
            ])
            // password / api_key は空で送られたら既存値を保持する（Controller 側で処理）
            ->add('password', PasswordType::class, ['mapped' => false, 'required' => false, 'always_empty' => true])
            ->add('apiKey', PasswordType::class, ['mapped' => false, 'required' => false, 'always_empty' => true])
            ->add('syncTarget', ChoiceType::class, [
                'choices' => [
                    '本会員すべて（受信可否は「配信状態」で表す。推奨）' => Config::TARGET_ACTIVE,
                    '受信する会員のみ（受信しない会員は blastmail に登録しない）' => Config::TARGET_MAILMAGA,
                ],
                'expanded' => true,
            ])
            ->add('withdrawAction', ChoiceType::class, [
                'choices' => [
                    'blastmail 側の読者を「配信停止」にする' => Config::WITHDRAW_SENDSTOP,
                    '何もしない' => Config::WITHDRAW_NONE,
                ],
                'expanded' => true,
            ])
            ->add('syncMode', ChoiceType::class, [
                'choices' => [
                    '全件（毎回すべての対象会員を送る）' => Config::MODE_FULL,
                    '差分（前回送信から変化した会員だけ送る）' => Config::MODE_DIFF,
                ],
                'expanded' => true,
            ])
            ->add('statusPolicy', ChoiceType::class, [
                'choices' => [
                    '意図マージ・安全: 前回同期から変わった側を新しい意思として採用する。ただし読者本人の「解除」は誰の操作でも上書きしない（推奨）' => Config::POLICY_INTENT_MERGE_SAFE,
                    '意図マージ・あと勝ち: 前回同期から変わった側を新しい意思として採用する。「解除」も EC-CUBE 側の新しい操作で上書きする' => Config::POLICY_INTENT_MERGE,
                    'blastmail を正とする: 状態は一切送らず、取り込みもしない（一覧取得なし。大規模向け）' => Config::POLICY_SOURCE_INITIAL_ONLY,
                    'EC-CUBE を正とする: 毎回 EC-CUBE の受信可否で上書きする（同期ごとに一覧取得）' => Config::POLICY_SOURCE_AUTHORITATIVE,
                ],
                'expanded' => true,
            ])
            ->add('batchSize', IntegerType::class, [
                'constraints' => [new NotBlank(), new Range(['min' => 100, 'max' => 500000])],
            ])
            ->add('csvCharset', ChoiceType::class, [
                'choices' => ['UTF-8' => 'UTF-8', 'Shift_JIS (SJIS-win)' => 'SJIS'],
            ])
            ->add('eventSyncEnabled', CheckboxType::class, ['required' => false])
            ->add('eventSyncTiming', ChoiceType::class, [
                'choices' => [
                    '画面の応答を返した後に送る（推奨。blastmail の応答待ちで画面を待たせない）' => Config::EVENT_TIMING_DEFERRED,
                    '画面の処理と同時に送る（送信が終わるまで保存の応答を返さない）' => Config::EVENT_TIMING_IMMEDIATE,
                ],
                'expanded' => true,
            ])
            ->add('lockMode', ChoiceType::class, [
                'choices' => [
                    'DB のアドバイザリロック（推奨。同じ DB を見る複数サーバーでも二重実行を防ぐ）' => Config::LOCK_DB,
                    'ファイル（flock。単一サーバー向け）' => Config::LOCK_FILE,
                ],
                'expanded' => true,
            ])
            ->add('bounceSyncEnabled', CheckboxType::class, ['required' => false])
            ->add('optoutImportEnabled', CheckboxType::class, ['required' => false])
            // 項目マッピング（blastmail 側の項目表示名）
            ->add('mapping_email', TextType::class, [
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(['max' => 100])],
                'data' => $options['mapping']['email'] ?? 'E-Mail',
            ]);

        foreach (array_keys(CustomerSource::fieldCatalog()) as $key) {
            $builder->add('mapping_'.$key, TextType::class, [
                'mapped' => false,
                'required' => false,
                'constraints' => [new Length(['max' => 100])],
                'data' => $options['mapping'][$key] ?? '',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Config::class,
            'mapping' => [],
        ]);
    }
}
