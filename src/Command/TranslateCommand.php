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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show stats and exits');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->client = new \GuzzleHttp\Client();
        $this->from = $input->getOption('from');
        $this->to = $input->getOption('to');
        $dontFlag = $input->getOption('no-fuzzy');
        $dryRun = $input->getOption('dry-run');

        $assets = [];
        $allAssets = $this->locoQuery('GET', 'export/locale/'.$this->to.'.json', ['no-folding' => 1]);

        foreach ($allAssets as $assetId => $string) {
            if (empty($string)) {
                $assets[] = $assetId;
            }
        }

        $output->writeln(sprintf('<info>Found %s assets, %s needs to be translated to %s (source %s)</info>', count($allAssets), count($assets), $this->to, $this->from));

        if ($dryRun) {
            return 0;
        }

        foreach ($assets as $assetId) {
            $data = $this->locoQuery('GET', 'translations/'.$assetId.'/'.$this->to);
            $output->writeln('Fetching '.$assetId.'…');
            if (!$data['translated']) {
                $source = $this->locoQuery('GET', 'translations/'.$assetId.'/'.$this->from);
                dump($source['translation']);
                $translation = $this->deeplQuery('GET', 'translate', ['text' => $source['translation'], 'source_lang' => strtoupper($this->from), 'target_lang' => strtoupper($this->to)]);
                dump($translation['translations'][0]['text']);
                $result = $this->locoQuery('POST', 'translations/'.$assetId.'/'.$this->to, ['data' => $translation['translations'][0]['text']]);
                if ($output->isVerbose()) {
                    dump($result);
                }
                if (!$dontFlag) {
                    $result = $this->locoQuery('POST', 'translations/'.$assetId.'/'.$this->to.'/flag', ['flag' => 'fuzzy']);
                    if ($output->isVerbose()) {
                        dump($result);
                    }
                }
            }
        }

        return 0;
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
        $path .= '?'.http_build_query($query);

        $res = $this->client->request($method, 'https://api.deepl.com/v2/'.$path, $params);

        $json = $res->getBody();
        $data = json_decode($json, true);

        return $data;
    }
}