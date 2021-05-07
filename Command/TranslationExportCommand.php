<?php
/**
 * Diglin GmbH - Switzerland.
 *
 * @author      Sylvain Rayé <support at diglin.com>
 * @category    DigitalDrink - OroCommerce
 * @copyright   2020 - Diglin (https://www.diglin.com)
 */

namespace Diglin\Bundle\DeeplBundle\Command;

use BabyMarkt\DeepL\DeepL;
use Doctrine\ORM\Query\Expr\Join;
use Oro\Bundle\TranslationBundle\Entity\Translation;
use Oro\Bundle\TranslationBundle\Provider\LanguageProvider;
use Oro\Bundle\TranslationBundle\Translation\Translator;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Yaml\Yaml;

class TranslationExportCommand extends Command implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    const FORMAT_CSV      = 'csv';
    const FORMAT_YML      = 'yml';
    const FORMAT_YAML     = 'yaml';
    const DEFAULT_DOMAINS = ['messages', 'workflows'];
    private DeepL $deepl;

    private array            $formats   = [self::FORMAT_CSV, self::FORMAT_YML, self::FORMAT_YAML];
    private ManagerRegistry  $doctrine;
    private LanguageProvider $languageProvider;
    private string           $defaultTranslationPath;
    private int              $bulkLimit = 50;

    public function __construct(
        ManagerRegistry $doctrine,
        LanguageProvider $languageProvider,
        string $defaultTranslationPath,
        string $name = null
    ) {
        parent::__construct($name);
        $this->doctrine = $doctrine;
        $this->languageProvider = $languageProvider;
        $this->defaultTranslationPath = $defaultTranslationPath;
    }

    public function configure()
    {
        $description = <<<DESC
Translate non-translated strings thanks to DeepL from an OroPlatform database and export them into a YAML or CSV file.
DESC;

        $this->setName('diglin:oro:deepl:translate:export');
        $this->setDescription($description);
        $this->addArgument(
            'locale',
            InputArgument::REQUIRED,
            'Target locale. e.g. de_DE'
        );

        $this
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Possible export format: yml or csv',
                'csv'
            )->addOption(
                'overwrite',
                'o',
                InputOption::VALUE_NONE,
                'Overwrites existing file. Attention: existing data will be merged'
            )->addOption(
                'deepl-api-key',
                null,
                InputOption::VALUE_OPTIONAL,
                'Deepl API Dev Key. API key is looked up 1) at the Oro Config, 2) via the cli parameter, 3) into the var/deepl-license.key'
            )->addOption(
                'disable-deepl',
                'd',
                InputOption::VALUE_NONE,
                'Disable DeepL translation engine'
            )->addOption(
                'domains',
                null,
                InputOption::VALUE_REQUIRED,
                'Message domains. Supported domains are: ' . implode(',', TranslationLoadCommand::SUPPORTED_DOMAINS),
                implode(',', self::DEFAULT_DOMAINS)
            )->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limit the number of messages to treat.'
            )
            ->addOption(
                'simulate',
                null,
                InputOption::VALUE_NONE,
                'Provide an estimation of the number of letters will be translate. Helpful to know the estimate cost of DeepL translation. No export or translation will be generated.'
            )
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $locale = $input->getArgument('locale');

        $format = $input->getOption('format');
        $overwrite = $input->getOption('overwrite');
        $domains = explode(',', $input->getOption('domains'));
        $simulate = $input->getOption('simulate');

        $availableLocales = $this->languageProvider->getAvailableLanguageCodes(true);

        $output->writeln(
            sprintf(
                '<info>Available locales</info>: %s. <info>Should be processed:</info> %s.',
                implode(', ', $availableLocales),
                $locale
            )
        );

        if (!in_array($format, $this->formats)) {
            $output->writeln(sprintf('<error>Format %s is not supported</error>', $format));

            return 0;
        }

        try {
            $this->initDeepl($input);
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
        }

        $totalChars = 0;

        foreach ($domains as $domain) {
            $output->writeln(sprintf('<info>Domain %s in progress...</info>', $domain));

            $exportRows = [];
            $translations = $this->getTranslations($domain, $locale, $input->getOption('limit'));

            $progress = new ProgressBar($output);
            $progress->setMessage(sprintf('Translations of %s domain in progress', $domain));
            $progress->setMaxSteps(count($translations));

            /**
             * $translation = Array (
             * [id] =>
             * [code] => de_DE
             * [value] =>
             * [key] => oro.sales.lead.twitter.description
             * [domain] => messages
             * [status] =>
             * [englishValue] => Twitter profile of the lead person or company.
             * );
             */
            foreach ($translations as $translation) {

                $progress->advance();

                if (empty($translation['englishValue']) || $translation['englishValue'] == ' ') continue;

                if (isset($this->deepl)) {
                    try {
                        $translated = $this->translate($translation);
                    } catch (\Exception $e) {
                        // TODO add logger or output information ?
                        $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                        continue;
                    }
                } else {
                    $translated = '';
                }

                if ($simulate) {
//                    $output->writeln($translation['key'] . ': ' . $translation['englishValue']);
                    $totalChars += (int) mb_strlen($translation['englishValue']);
                }

                $exportRows[] = [
                    'key'           => $translation['key'],
                    'english_value' => $translation['englishValue'],
                    $locale         => $translated,
                ];
            }

            $output->writeln('');
            $progress->finish();

            $filename = $this->defaultTranslationPath . DIRECTORY_SEPARATOR . $domain . '.' . $locale
                . (!$overwrite ? '-' . date('dmY-his') : '')
                . '.' . $format;

            if (!$simulate && $overwrite && file_exists($filename)) {
                copy($filename, $filename . '.backup');
            }

            if (!$input->getOption('simulate') && count($exportRows)) {
                switch ($format) {
                    case self::FORMAT_YML:
                    case self::FORMAT_YAML:
                        $this->writeYMLFile($locale, $filename, $exportRows);
                        break;
                    case self::FORMAT_CSV:
                    default:
                        $this->writeCSVFile($locale, $filename, $exportRows);
                }
            }

            if (!$simulate) {
                $output->writeln(sprintf('<fg=green>File %s successfully written for the domain %s</>', $filename, $domain));
            }
        }

        if ($simulate) {
            $output->writeln(sprintf('Number total of characters which could be translated: %d chars.', $totalChars));
        }

        if (isset($this->deepl)) {
            $usageArray = $this->deepl->usage();
            $output->writeln(sprintf('You have used %s of %s in the current billing period.', $usageArray['character_count'], $usageArray['character_limit']));
        }
    }

    /**
     * select translation.id, language.code, translation.value as value, translationKey.key as key,
     * translationKey.domain as domain, (CASE WHEN translation.value IS NULL THEN false ELSE true END) as status,
     * translationEn.value as englishValue from oro_language as language left join oro_translation_key as
     * translationKey on 1 = 1 left join oro_translation as translationEn on translationEn.translation_key_id =
     * translationKey.id AND translationEn.language_id = 1 left join oro_translation as translation on
     * translation.language_id = language.id AND translation.translation_key_id = translationKey.id where
     * translationEn.value <> '' and code = 'de_DE' and translation.value IS null order by key ASC
     */
    private function getTranslations(string $domain, string $locale, int $limit = null): array
    {
        $em = $this->doctrine->getManagerForClass(Translation::class);
        /** @var \Doctrine\ORM\QueryBuilder $qb */
        $qb = $em->createQueryBuilder('language');
        $qb->select(
            [
                'translation.id',
                'language.code',
                'translation.value as value',
                'translationKey.key as key',
                'translationKey.domain as domain',
                '(CASE WHEN translation.value IS NULL THEN false ELSE true END) as status',
                'translationEn.value as englishValue',
            ]
        )
            ->from('OroTranslationBundle:Language', 'language')
            ->leftJoin(
                'OroTranslationBundle:TranslationKey',
                'translationKey',
                Join::WITH,
                $qb->expr()->eq(1, 1),
            )
            ->leftJoin(
                'OroTranslationBundle:Translation',
                'translationEn',
                Join::WITH,
                $qb->expr()->andX(
                    $qb->expr()->eq('translationEn.translationKey', 'translationKey'),
                    $qb->expr()->eq('translationEn.language', ':en_param')
                )
            )
            ->leftJoin(
                'OroTranslationBundle:Translation',
                'translation',
                Join::WITH,
                $qb->expr()->andX(
                    $qb->expr()->eq('translation.language', 'language'),
                    $qb->expr()->eq('translation.translationKey', 'translationKey'),
                )
            )
            ->andWhere(
                $qb->expr()->neq('translationEn.value', "''"),
                $qb->expr()->eq('language.code', ':locale'),
                $qb->expr()->eq('translationKey.domain', ':domain'),
                $qb->expr()->isNull('translation.value'),
            )
            ->addOrderBy('translationKey.key');

        $qb->setParameter('en_param', $this->languageProvider->getDefaultLanguage());
        $qb->setParameter('locale', $locale);
        $qb->setParameter('domain', $domain);

        $query = $qb->getQuery();

        if ($limit) {
            $query->setMaxResults($limit);
        }

        return $query->getResult();
    }

    public function getDeeplApiKey(InputInterface $input): string
    {
        $projectDir = $this->container->getParameter('kernel.project_dir');
        $licenseFile = $projectDir . DIRECTORY_SEPARATOR . 'var/deepl-license.key';

        return $this->container
                ->get('oro_config.manager')
                ->get('diglin_deepl.api_key')
            ?? $input->getOption('deepl-api-key')
            ?? (file_exists($licenseFile) ? trim(file_get_contents($licenseFile)) : '');
    }

    private function writeYMLFile($locale, $filename, $rows): void
    {
        $data = [];
        $convertedRows = [];

        try {
            $data = Yaml::parseFile($filename);
        } catch (\Exception $e) {
            // do nothing
        }

        foreach ($rows as $row) {
            $convertedRows[$row['key']] = $row[$locale];
        }

        $convertedRows = array_merge($data, $convertedRows);

        unset($rows);
        ksort($convertedRows, SORT_STRING);

        file_put_contents($filename, Yaml::dump($convertedRows));
    }

    private function writeCSVFile($locale, $filename, $rows): void
    {
        $csvHandle = fopen($filename, 'w+');
        $headers = ['key', 'english_value', $locale];
        fputcsv($csvHandle, $headers);

        foreach ($rows as $row) {
            fputcsv($csvHandle, $row);
        }

        fclose($csvHandle);
    }

    private function initDeepl(InputInterface $input): void
    {
        if (!$input->getOption('disable-deepl') && !$input->getOption('simulate')) {
            $apiKey = $this->getDeeplApiKey($input);

            if (empty($apiKey)) {
                throw new \Exception('DeepL API Key not defined. Please, go to OroPlatform Backoffice, menu System > Configuration > System Configuration > Integrations > DeepL. Or provide the key by the parameter "deepl-api-key" or set the key into the var/deepl-license.key file.');
            } else {
                $this->deepl = new DeepL($apiKey);
            }
        }
    }

    private function translate(array $translation): string
    {
        $tagsToIgnore = ['{{', '}}'];
        $tagsIgnored = ['<ignore>', '</ignore>'];

        $srcLang = Translator::DEFAULT_LOCALE;
        $localeLocale = explode('_', $translation['code']);
        $localeLang = $localeLocale[0];// e.g. de for german
        $text = str_replace($tagsToIgnore, $tagsIgnored, $translation['englishValue']);
        $deeplTrans = $this->deepl->translate($text, $srcLang, $localeLang, 'xml', ['ignore']);

        return str_replace($tagsIgnored, $tagsToIgnore, $deeplTrans[0]['text']);
    }
}
