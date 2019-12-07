<?php

namespace Truonglv\PaymentBaoKim;

use XF\AddOn\AbstractSetup;
use XF\Entity\PaymentProvider;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use Truonglv\PaymentBaoKim\Payment\BaoKim;
use Truonglv\PaymentBaoKim\DevHelper\SetupTrait;

class Setup extends AbstractSetup
{
    use SetupTrait;
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1()
    {
        /** @var PaymentProvider $em */
        $em = $this->app->em()->create('XF:PaymentProvider');

        $em->provider_id = BaoKim::PROVIDER_ID;
        $em->provider_class = 'Truonglv\PaymentBaoKim:BaoKim';
        $em->addon_id = 'Truonglv/PaymentBaoKim';

        $em->save();
    }

    public function uninstallStep1()
    {
        $this->db()
            ->delete('xf_payment_provider', 'provider_id = ?', BaoKim::PROVIDER_ID);
    }
}
