<?php

namespace App\DataFixtures;

use App\Entity\FaqEntry;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class FaqFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $pairs = [
            // Livraison / Shipping
            ['livraison', 'shipping', 'Quels sont vos délais de livraison ?', 'Les commandes sont préparées sous 1 à 2 jours ouvrables. La livraison prend ensuite de 3 à 7 jours ouvrables selon la province de destination.', 'What are your delivery times?', 'Orders are prepared within 1 to 2 business days. Delivery then takes 3 to 7 business days depending on the destination province.'],
            ['livraison', 'shipping', 'Livrez-vous partout au Canada ?', 'Oui, nous livrons dans toutes les provinces et territoires canadiens, y compris les régions nordiques. Des délais supplémentaires peuvent s\'appliquer pour les régions éloignées.', 'Do you ship anywhere in Canada?', 'Yes, we ship to all Canadian provinces and territories, including northern regions. Additional delays may apply for remote areas.'],

            // Retours / Returns
            ['retours', 'returns', 'Quelle est votre politique de retour ?', 'Vous disposez de 30 jours après réception pour retourner un article non utilisé dans son emballage d\'origine. Les frais de retour sont à la charge du client sauf en cas d\'erreur de notre part.', 'What is your return policy?', 'You have 30 days after delivery to return an unused item in its original packaging. Return shipping costs are the customer\'s responsibility unless the return is due to our error.'],
            ['retours', 'returns', 'Comment obtenir un remboursement ?', 'Une fois le retour reçu et inspecté, le remboursement est émis sur votre mode de paiement d\'origine sous 5 à 10 jours ouvrables.', 'How do I get a refund?', 'Once your return is received and inspected, the refund is issued to your original payment method within 5 to 10 business days.'],

            // Paiement / Payment
            ['paiement', 'payment', 'Quels modes de paiement acceptez-vous ?', 'Nous acceptons les cartes de crédit Visa, Mastercard et American Express, ainsi que plusieurs autres modes de paiement en ligne sécurisés.', 'What payment methods do you accept?', 'We accept Visa, Mastercard and American Express credit cards, as well as several other secure online payment methods.'],
            ['paiement', 'payment', 'Le paiement en ligne est-il sécurisé ?', 'Oui, toutes les transactions sont chiffrées et traitées via un prestataire de paiement certifié. Vos données bancaires ne sont jamais stockées sur nos serveurs.', 'Is online payment secure?', 'Yes, all transactions are encrypted and processed through a certified payment provider. Your banking details are never stored on our servers.'],

            // Taxes
            ['taxes', 'taxes', 'Comment sont calculées les taxes ?', 'Les taxes sont calculées automatiquement selon la province de livraison, conformément aux taux en vigueur (TPS, TVQ ou TVH selon la province).', 'How are taxes calculated?', 'Taxes are calculated automatically based on the delivery province, according to the applicable rates (GST, QST or HST depending on the province).'],

            // Compte / Account
            ['compte', 'account', 'Dois-je créer un compte pour commander ?', 'Non, vous pouvez commander en tant qu\'invité. Créer un compte vous permet toutefois de suivre vos commandes et de retrouver votre historique d\'achats.', 'Do I need an account to order?', 'No, you can check out as a guest. Creating an account lets you track your orders and access your purchase history.'],
        ];

        foreach ($pairs as [$categoryFr, $categoryEn, $questionFr, $answerFr, $questionEn, $answerEn]) {
            $groupKey = bin2hex(random_bytes(8));

            $fr = new FaqEntry();
            $fr->setLocale('fr');
            $fr->setGroupKey($groupKey);
            $fr->setCategory($categoryFr);
            $fr->setQuestion($questionFr);
            $fr->setAnswer($answerFr);
            $manager->persist($fr);

            $en = new FaqEntry();
            $en->setLocale('en');
            $en->setGroupKey($groupKey);
            $en->setCategory($categoryEn);
            $en->setQuestion($questionEn);
            $en->setAnswer($answerEn);
            $manager->persist($en);
        }

        $manager->flush();
    }
}
