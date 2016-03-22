<?php
/**
 * @author artyfarty
 */

namespace Arty\VKDownloadFavs\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use getjump\Vk\Core as Vk;

class Download extends Command {
    /** @var Vk */
    protected $vk;

    /** @var InputInterface */
    protected $input;

    /** @var OutputInterface */
    protected $output;

    /** @var int[] */
    protected $owners;


    public function configure() {
        $this->setDefinition(
            [
                new InputOption('token', 't', InputOption::VALUE_REQUIRED, 'VK auth token'),
                new InputOption('owners', 'o', InputOption::VALUE_OPTIONAL, 'Owners of media to download'),
            ]
        )
            ->setDescription('Скачать фото из избранных фото, постов и документов');
    }

    function l($message, $nest = 0, $loglevel = "info") {
        $pad = str_pad("", $nest*2, " ");

        $this->output->writeln($pad.$message);
    }

    function getLogger($group, $nest = 0) {
        return function($msg, $n = 0, $loglevel = "info") use ($nest, $group) {
            $this->l("[$group] $msg", $n + $nest, $loglevel);
        };
    }

    function savePhoto($photo, $tags = [], $nest = 0) {
        $l = $this->getLogger("save", $nest);

        $vkUrl = null;
        $chosenUrl = null;
        $sizeChosen = null;

        $genSavePath = function($url, $nest = 0) use ($photo, $l) {
            $saveDir = "downloads/{$photo->owner_id}/";
            if (!file_exists($saveDir)) {
                $l("Making owner dir", 1);
                mkdir($saveDir);
            }

            preg_match("!(jpg|png|gif)!siu", $url, $em);
            $saveName = "{$photo->id}.{$em[1]}";

            $path = $saveDir . $saveName;

            $l("Saving $url -> $path", $nest);

            return $path;
        };

        $l("Saving photo {$photo->id}");

        foreach ([2560, 1280, 807, 604, 130, 75] as $size) {
            if (isset($photo->{"photo_$size"})) {
                $vkUrl = $photo->{"photo_$size"};
                $sizeChosen = $size;
                break;
            }
        }
        $l("Best vk photo size is $sizeChosen", 1);

        if (preg_match("!(https?:.*?\\.(?:jpg|png|gif))!siu", $photo->text, $m)) {
            $chosenUrl = $m[1];
            $sizeChosen = "full";
            $l("Got fullsize from comment", 1);
        } else {
            $chosenUrl = $vkUrl;
            $l("Using best vk photo", 1);
        }

        $path = $genSavePath($chosenUrl, 1);

        if (file_exists($path)) {
            $l("Photo already saved", 2);
            return;
        } else {
            $data = @file_get_contents($chosenUrl);
            if ($data) {
                file_put_contents($path, $data);
                $l("Saved!", 2);
            } else {
                $l("Could not save", 2);
                if ($chosenUrl != $vkUrl) {
                    $l("Falling back to vk best", 2);
                    $path = $genSavePath($vkUrl, 2);

                    $data = @file_get_contents($vkUrl);
                    if ($data) {
                        file_put_contents($path, $data);
                        $l("Saved!", 2);
                    } else {
                        $l("Could not save :((((", 2);
                    }
                }
            }
        }

        if ($sizeChosen) {
            $tags[] = "size_$sizeChosen";
        }

        $tMix = implode(',', $tags);

        $l("Tagging as $tMix", 1);

        system("tag --set $tMix $path");
    }

    /**
     * @param string $text
     * @return string[]
     */
    function scrapeTags($text) {
        if (preg_match_all("!\\s+\\#([a-zA-Z0-9_-]+)!siu", $text, $postTags)) {
            $result = [];

            foreach ($postTags[1] as $t) {
                $result[] = mb_convert_case($t, MB_CASE_LOWER, "utf-8");
            }

            return $result;
        }

        return [];
    }

    function getPostProcessor($filterBy = null, $nest = 0) {
        return function ($i, $post) use ($filterBy, $nest) {
            $l = $this->getLogger("process_post", $nest);

            $l("Processing post {$post->id}");

            if ($this->owners && in_array($post->owner_id, $this->owners)) {
                $l("Diff. owner, skipping", 1);
                return;
            }

            if (!(isset($post->attachments) && is_array($post->attachments))) {
                $l("Post has no attachments", 1);
                return;
            }

            $tags = $this->scrapeTags($post->text);

            if (count($tags) > 10 && $post->attachments > 4) {
                $tags = ["batch"];
            }

            foreach ($post->attachments as $a) {
                $l("Processing attached {$a->type}", 1);
                if ($a->type === 'photo') {
                    if ($filterBy && !in_array($a->photo->id, $filterBy)) {
                        $l("skipping photo {$a->photo->id} cause it's not on filter", 2);
                        continue;
                    }

                    $this->savePhoto($a->photo, $tags, 2);
                } elseif ($a->type === 'doc') {
                    $saveDir = "downloads/{$post->owner_id}/";
                    if (!file_exists($saveDir)) {
                        mkdir($saveDir);
                    }

                    $path = "$saveDir/{$a->doc->title}";

                    $l("Saving doc from {$a->doc->url} -> $path", 2);
                    if (file_exists($path)) {
                        $l("Doc already saved", 2);
                        return;
                    } else {
                        $data = @file_get_contents($a->doc->url);
                        if ($data) {
                            file_put_contents($path, $data);
                            $l("Saved!", 2);
                        } else {
                            $l("Could not save :(", 2);
                        }
                    }
                }
            }


            //echo "Unliking post {$post->id}\n";
            //$vk->request('likes.delete', ['type' => 'post', 'item_id' => $post->id])->execute();

        };
    }

    public function execute(InputInterface $input, OutputInterface $output) {
        $this->vk = Vk::getInstance()->apiVersion('5.34');
        $this->vk->setToken($input->getOption('token'));

        $this->input = $input;
        $this->output = $output;

        if ($input->getOption('owners')) {
            $this->owners = explode(',', $input->getOption('owners'));
        }

        $postsToGet = [];
        $photosToGet = [];

        $this->l("Enumerating fave photos");

        foreach ($this->vk->request('fave.getPhotos')->batch(800) as $b) {
            $processedB = 0;
            $b->each(
                function ($i, $photo) use (&$postsToGet, &$photosToGet, &$processedB) {
                    //$this->l("Processing photo#{$photo->id}", 1);
                    $processedB++;

                    if ($this->owners && in_array($photo->owner_id, $this->owners)) {
                        //$this->l("Diff. owner, skipping", 2);
                        return;
                    }

                    $this->l("Processing photo#{$photo->id}", 1);

                    if (property_exists($photo, 'post_id')) {
                        $this->l("Will process post #{$photo->post_id} instead of photo #{$photo->id}", 2);
                        $postsToGet[] = "{$photo->owner_id}_{$photo->post_id}";
                        $photosToGet[] = $photo->id;
                    } else {
                        $this->l("Will save now", 2);
                        $this->savePhoto($photo, [], 2);
                    }

                    //echo "Unliking photo {$photo->id}\n";
                    //$vk->request('likes.delete', ['type' => 'photo', 'item_id' => $photo->id])->execute();
                }
            );
        }


        $this->l("Downloading posts for photos by chunks");
        foreach (array_chunk($postsToGet, 50) as $i => $postsBlock) {
            $this->l("Chunk $i", 1);
            $this->vk
                ->request('wall.getById', ['posts' => $postsBlock])
                ->each($this->getPostProcessor($photosToGet, 1));
        }

        $this->l("Downloading implicitly liked posts");
        foreach ($this->vk->request('fave.getPosts')->batch(800) as $b) {
            $this->l("Processing batch block...", 1);
            $b->each($this->getPostProcessor(null, 1));
        }

        $this->l("Done");
    }
}