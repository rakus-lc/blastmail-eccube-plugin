<?php

namespace Plugin\BlastmailSync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Annotation\EntityExtension;

/**
 * 会員にメール受信可否（オプトイン）を追加する。
 *
 * 標準の EC-CUBE 4.3 には会員ごとの受信可否の項目が無い。公式のメルマガ管理プラグイン等が
 * `mailmaga_flg` を持っている場合はそちらを優先し、無い環境ではこの項目を使う
 * （Service\CustomerSource::isOptIn を参照）。
 *
 * @EntityExtension("Eccube\Entity\Customer")
 */
trait CustomerOptInTrait
{
    /**
     * メールマガジンを受信する
     *
     * @ORM\Column(name="blastmail_opt_in", type="boolean", options={"default":true})
     */
    private $blastmailOptIn = true;

    public function isBlastmailOptIn(): bool
    {
        return (bool) $this->blastmailOptIn;
    }

    public function getBlastmailOptIn(): bool
    {
        return (bool) $this->blastmailOptIn;
    }

    public function setBlastmailOptIn(?bool $optIn): self
    {
        $this->blastmailOptIn = (bool) $optIn;

        return $this;
    }
}
