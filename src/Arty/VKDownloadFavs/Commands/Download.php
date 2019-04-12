<?php
/**
 * @author artyfarty
 */

namespace Arty\VKDownloadFavs\Commands;

use Arty\VKDownloadFavs\VkRateLimitWatcher;
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

    /**
     * @var VkRateLimitWatcher $rlWatcher
     */
    protected $rlw;

    /**
     * @var bool $dislike
     */
    protected $dislike = false;


    public function configure() {
        $this->setDefinition(
            [
                new InputOption('token', 't', InputOption::VALUE_REQUIRED, 'VK auth token'),
                new InputOption('owners', 'o', InputOption::VALUE_OPTIONAL, 'Owners of media to download'),
                new InputOption('rate_limit', null, InputOption::VALUE_OPTIONAL, 'Rate limit (usec)', "600000"),
                new InputOption("dislike", null, InputOption::VALUE_OPTIONAL, "Dislike after downloading", false),
                //new InputOption('retries', 'r', InputOption::VALUE_OPTIONAL, 'Owners of media to download', 2),
            ]
        )
            ->setDescription('Скачать фото из избранных фото, постов и документов');
    }

    public function execute(InputInterface $input, OutputInterface $output) {
        $this->vk = Vk::getInstance()->apiVersion('5.69');
        $this->vk->setToken($input->getOption('token'));

        $this->input = $input;
        $this->output = $output;
        $this->dislike = !!$input->getOption("dislike");

        if ($this->dislike) {
            $this->l("Warning! Will dislike after successful DL!", 0, "warn");
        }

        if ($input->getOption('owners')) {
            $this->owners = explode(',', $input->getOption('owners'));
            foreach ($this->owners as &$o) {
                $o = +$o;
            }
        }

        $this->rlw = new VkRateLimitWatcher(+$input->getOption('rate_limit'), $output);

        $postsToGet = [];
        $photosToGet = [];

        $this->l("Enumerating fave photos");

        $batchN = 0;
        $photoN = 0;
        $photoS = 0;

        $this->rlw->wantToRequest();
        foreach ($this->vk->request('fave.getPhotos')->batch(500) as $b) {
            $this->l("Processing batch $batchN, $photoN photos so far, $photoS skipped", 1);
            $b->each(
                function ($i, $photo) use (&$postsToGet, &$photosToGet, &$photoN, &$photoS) {
                    $this->l("Processing https://vk.com/fave?z=photo{$photo->owner_id}_{$photo->id}", 1);

                    if ($this->owners && !in_array($photo->owner_id, $this->owners)) {
                        $this->l("Diff. owner, skipping", 2);
                        $photoS++;
                        return;
                    }

                    $this->l("Processing photo#{$photo->id}", 1);

                    if (property_exists($photo, 'post_id')) {
                        $this->l("Will process post #{$photo->post_id} instead of photo #{$photo->id}", 2);
                        $postsToGet[] = "{$photo->owner_id}_{$photo->post_id}";
                        $photosToGet[] = $photo->id;
                    } else {
                        $this->l("Will save now", 2);
                        $photoResult = $this->savePhoto($photo, [], 2);

                        if ($photoResult) {
                            $this->dislike($photo->id, "photo", 2);
                        } else {
                            $this->l("Cannot dislike photo#{$photo->id} because it's not saved ok", 2);
                        }
                    }
                    $photoN++;
                }
            );

            $this->rlw->wantToRequest();
            $batchN++;
        }


        $this->l("Downloading posts for photos by chunks");
        foreach (array_chunk($postsToGet, 50) as $i => $postsBlock) {
            $this->rlw->wantToRequest();
            $this->l("Chunk $i", 1);
            $this->vk
                ->request('wall.getById', ['posts' => $postsBlock])
                ->each($this->getPostProcessor($photosToGet, 1));
        }

        $this->l("Downloading implicitly liked posts");
        foreach ($this->vk->request('fave.getPosts')->batch(800) as $b) {
            $this->l("Processing batch block...", 1);
            $b->each($this->getPostProcessor(null, 1));
            $this->rlw->wantToRequest();
        }

        $this->l("Done");
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

    /**
     * @param       $photo
     * @param array $tags
     * @param int   $nest
     * @return bool
     */
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

        $retries = 2;

        // ДА! Впервые в жизни я столкнулся с ситуацией когда GOTO реально жжот!
        save_photo:
        $path = $genSavePath($chosenUrl, 1);

        $this->csvWrite("processed_content", ["photo", $photo->id, $path]);


        if (file_exists($path)) {
            $l("Photo already saved", 2);
            return true;
        } else {
            $data = @file_get_contents($chosenUrl);
            if ($data) {
                file_put_contents($path, $data);
                $l("Saved!", 2);
            } else {
                $l("Could not save", 2);

                if ($retries) {
                    $l("Retrying", 2);
                    $retries--;
                    goto save_photo;
                } else {
                    if ($chosenUrl != $vkUrl) {
                        $l("Falling back to vk best", 2);
                        $chosenUrl = $vkUrl;
                        goto save_photo;
                    }

                    $l("Giving up", 2);
                }

                return false;
            }
        }

        if ($sizeChosen) {
            $tags[] = "size_$sizeChosen";
        }

        $tMix = implode(',', $tags);

        $l("Tagging as $tMix", 1);

        system("tag --set $tMix $path");

        return true;
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

            $l("Processing post https://vk.com/fave?section=likes_posts&w=wall{$post->owner_id}_{$post->id}");

            if ($this->owners && !in_array($post->owner_id, $this->owners)) {
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

            $this->csvWrite("processed_content", ["post", $post->id]);

            $result = true;

            foreach ($post->attachments as $a) {
                $l("Processing attached {$a->type}", 1);
                if ($a->type === 'photo') {
                    if ($filterBy && !in_array($a->photo->id, $filterBy)) {
                        $l("skipping photo {$a->photo->id} cause it's not on filter", 2);
                        continue;
                    }

                    $result = $result && $this->savePhoto($a->photo, $tags, 2);
                } elseif ($a->type === 'doc') {
                    $saveDir = "downloads/{$post->owner_id}/";
                    if (!file_exists($saveDir)) {
                        mkdir($saveDir);
                    }

                    $retries = 2;
                    $path = "$saveDir/{$a->doc->title}";

                    $this->csvWrite("processed_content", ["doc", $a->doc->id, $path]);

                    save_post_photo:

                    $l("Saving doc from {$a->doc->url} -> $path", 2);
                    if (file_exists($path)) {
                        $l("Doc already saved", 2);
                    } else {
                        $data = @file_get_contents($a->doc->url);
                        if ($data) {
                            file_put_contents($path, $data);
                            $l("Saved!", 2);
                        } else {
                            $l("Could not save :(", 2);
                            if ($retries) {
                                $l("Retrying", 2);
                                $retries--;
                                goto save_post_photo;
                            }

                            $l("Giving up", 2);
                            $result = false;
                        }
                    }
                }
            }

            if ($result) {
                $this->dislike($post->id, "post", 1);
            } else {
                $l("Cannot dislike post#{$post->id} because it's not saved completely", 1);
            }

        };
    }

    /**
     * @var mixed[][] $_dislikeBuffer
     */
    protected $_dislikeBuffer = [];

    protected function dislike($id, $type = "photo", $nest = 0) {
        if (!$this->dislike) {
            return;
        }

        $this->l("Disliking {$type}#{$id} (buffered)", $nest);
        $this->csvWrite("disliked", [$type, $id, date_create()->format("Y-m-d H:i:s")]);

        $this->_dislikeBuffer[] = ['type' => $type, 'item_id' => $id];

        if (count($this->_dislikeBuffer) >= 10) {
            $this->l("Executing dislike buffer", $nest);

            $code = "";
            foreach ($this->_dislikeBuffer as $db) {
                $code .= "API.likes.delete(" . json_encode($db) . "); ";
            }

            $code .= "return true;";

            $this->l("Code: $code");

            $this->rlw->wantToRequest();
            $this->vk->request('execute', ['code' => $code])->execute();
            $this->_dislikeBuffer = [];
        }
    }


    /** @var array $_csvs  */
    protected $_csvs = [];
    protected function csvWrite($name, $data = []) {
        if (!array_key_exists($name, $this->_csvs)) {
            $this->_csvs[$name] = fopen("{$name}.log.csv", "a");
        }

        fputcsv($this->_csvs[$name], $data, ";");
    }
}