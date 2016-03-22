<?php
/**
 * @author artyfarty
 */

namespace Arty\VKDownloadFavs\Commands;

use getjump\Vk\Auth as VkAuth;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class GetToken extends Command {
    public function configure() {
        $this->setDefinition(
            [
                new InputOption("app_id", "a", InputOption::VALUE_REQUIRED, "Vk App id"),
                new InputOption("app_secret", "s", InputOption::VALUE_REQUIRED, "Vk App secret"),
                new InputOption("code", "c", InputOption::VALUE_OPTIONAL, "Code after 1st step")
            ]
        );
    }

    public function execute(InputInterface $input, OutputInterface $output) {
        $auth = VkAuth::getInstance();
        
        $auth
            ->setAppId($input->getOption("app_id"))
            ->setScope('messages,photos,groups,status,wall,offline')
            ->setSecret($input->getOption("app_secret"))
            ->setRedirectUri('https://oauth.vk.com/blank.html');

        if ($input->getOption('code')) {
            $output->writeln("Getting your token...");
            $token = $auth->getToken($input->getOption('code'))->token;
            $output->writeln("Success! Your token is: \n$token");
        } else {
            $output->writeln("1. Navigate your browser to this URL: \n   {$auth->getUrl()}");
            $output->writeln("2. Copy code from the dress bar despite vk saying not to do this");
            $output->writeln("3. Relaunch this command with additional option -c your_code_here");
        }
    }
}