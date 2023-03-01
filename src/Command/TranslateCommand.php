<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function GuzzleHttp\Psr7\build_query;

/**
 * Class TranslateCommand
 *
 * @package App\Command
 */
class TranslateCommand extends Command
{
    /** @var \GuzzleHttp\Client */
    private $client;

    private $from;

    private $to;

    protected function configure()
    {
        $this
            ->setName('app:translate')
            ->setDescription('Translate localized content')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'From language code', 'en')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'To language code', 'fr')
            ->addOption('no-fuzzy', null, InputOption::VALUE_NONE, 'Do not flag automatic translations as fuzzy')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Update all translations')
            ->addOption('prefix', null, InputOption::VALUE_OPTIONAL, 'Translate only starting with the prefix')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show stats and exits');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->client = new \GuzzleHttp\Client();
        $this->from = $input->getOption('from');
        $this->to = $input->getOption('to');
        $dontFlag = $input->getOption('no-fuzzy');
        $translateAll = $input->getOption('force');
        $prefix = $input->getOption('prefix');
        $dryRun = $input->getOption('dry-run');

        $assets = [];
        $allAssets = $this->locoQuery('GET', 'export/locale/'.$this->to.'.json', ['no-folding' => 1]);

        foreach ($allAssets as $assetId => $strings) {
            if ($prefix && substr($assetId, 0, strlen($prefix)) != $prefix) {
                continue;
            }
            if (!is_array($strings)) {
                $strings = [$strings];
            }
            foreach ($strings as $string) {
                if (empty($string) || $translateAll || strpos($string, '<syntax>') !== false || strpos($string, '<var>') !== false) {
                    $assets[] = $assetId;

                    if ($output->isVerbose()) {
                        dump($assetId);
                        dump($strings);
                    }
                }
            }
        }

        $output->writeln(sprintf('<info>Found %s assets, %s needs to be translated to %s (source %s)</info>', count($allAssets), count($assets), $this->to, $this->from));

        if ($dryRun) {
            return 0;
        }

        foreach ($assets as $assetId) {
            $source = $this->locoQuery('GET', 'translations/'.$assetId.'/'.$this->from);

            if ($output->isVerbose()) {
                dump($source);
            }

            $this->translateString($output, $source);

            if (count($source['plurals'])) {
                foreach ($source['plurals'] as $plural) {
                    $this->translateString($output, $plural);
                }

                if ($source['locale']['plurals']['length'] < $this->numberOfPlurals($this->to)) {
                    $output->writeln(sprintf('<error>You should create a new plural form for %s (%s)</error>', $source['id'], $this->to));
                }
            }

            if (!$dontFlag && !empty($translatedString)) {
                $result = $this->locoQuery('POST', 'translations/'.$assetId.'/'.$this->to.'/flag', ['flag' => 'fuzzy']);
                if ($output->isVerbose()) {
                    dump($result);
                }
            }
        }

        return 0;
    }

    protected function translateString($output, $source)
    {
        $output->writeln('Translating '.$source['id'].'…');

        $toTranslate = preg_replace('/%([^% ]+)%/', '<var>$1</var>', $source['translation']);

        $toTranslate = str_replace([
            '[0,1]',
            '{0}',
            '{1}',
            '|]1,Inf[',
        ], [
            '<syntax>[0,1]</syntax>',
            '<syntax>{0}</syntax>',
            '<syntax>{1}</syntax>',
            '<syntax>|]1,Inf[</syntax>',
        ], $toTranslate);

        dump($toTranslate);

        $translation = $this->deeplQuery('GET', 'translate', ['text' => $toTranslate, 'source_lang' => strtoupper($this->from), 'target_lang' => strtoupper($this->to)]);

        $translatedString = preg_replace('/\<var\>([^<]+)\<\/var\>/', '%$1%', $translation['translations'][0]['text']);
        $translatedString = preg_replace('/\<syntax\>([^<]+)\<\/syntax\>/', '$1', $translatedString);
        $translatedString = preg_replace('/\[([0-9]+)\.([0-9]+)\]/', '[$1,$2]', $translatedString);

        dump($translatedString);

        $result = $this->locoQuery('POST', 'translations/'.$source['id'].'/'.$this->to, ['data' => $translatedString]);

        if ($output->isVerbose()) {
            dump($result);
        }

        return $translatedString;
    }

    protected function locoQuery($method, $path, $query = [])
    {
        $locoKey = $_SERVER['LOCO_KEY'];

        $params = [
            'headers' => [
                'Authorization' => 'Loco '.$locoKey,
            ]
        ];

        if ($method == 'GET') {
            $path .= '?'.http_build_query($query);
        } elseif ($method == 'POST') {
            if (isset($query['data'])) {
                $params['body'] = $query['data'];
            } else {
                $params['form_params'] = $query;
            }
        }

        $res = $this->client->request($method, 'https://localise.biz/api/'.$path, $params);

        $json = $res->getBody();
        $data = json_decode($json, true);

        return $data;
    }

    protected function deeplQuery($method, $path, $query = [])
    {
        $deeplKey = $_SERVER['DEEPL_KEY'];

        $params = [
            'headers' => [
                'User-Agent' => 'deeploco (maxime@maximevalette.com)',
            ]
        ];

        $query['auth_key'] = $deeplKey;

        $query['tag_handling'] = 'xml';
        $query['ignore_tags'] = 'var,syntax,a,strong,p,br';

        $path .= '?'.http_build_query($query);

        $res = $this->client->request($method, 'https://api.deepl.com/v2/'.$path, $params);

        $json = $res->getBody();
        $data = json_decode($json, true);

        return $data;
    }

    protected function numberOfPlurals($lang)
    {
        $lang = strtolower($lang);

        if ($lang === 'pl') {
            return 3;
        }

        return 2;
    }
}