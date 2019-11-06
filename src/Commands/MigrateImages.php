<?php

namespace Drupal\bio_image_migration\Commands;

require dirname(__FILE__,3) . '/vendor/autoload.php';

use Drush\Commands\DrushCommands;
use Drupal\media\Entity\Media as Media;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

# imports the Google Cloud client library
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 */
class MigrateImages extends DrushCommands {
    /**
     * Import all images into media entities, with vision api tags.
     *
     * @param string $path
     *   Path to csv file.
     *
     * @command bio-migrate:migrateImages
     * @aliases mi-image
     * @options arr An option that takes multiple values.
     * @options msg Whether or not an extra message should be displayed to the user.
     * @usage bio-migrate:image path_to_csv --show_log
     *   Migrate images into media entities
     */     
    public function migrateImages($path, $options = ['show_log' => FALSE, 'img_path' => FALSE]){
        # instantiates a client
        $imageAnnotator = new ImageAnnotatorClient([
#                   'credentials' => dirname(DRUPAL_ROOT,1).'/credentials/sapient-scps-gcloud-image-tagging-credentials.json'
                    'credentials' => 'C:\Users\barkonda1\code\gcp-credentials.json'
        ]);
        if($options['img_path']){
            $imgPath = $options['img_path'];
        }
        else{
            $imgPath = '/sites/default/files/legacy/bioorg/images/';
        }
        
        # Get CSV File convert to array. 
        $csv =  array_map('str_getcsv', file($path , FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        foreach($csv as $row){
            $fileName = $row[6];
            $filePath = DRUPAL_ROOT.$imgPath.$fileName;
            # prepare the image to be annotated
            $image = file_get_contents($filePath);
            # performs label detection on the image file
            $response = $imageAnnotator->labelDetection($image);
            $labels = $response->getLabelAnnotations();
            # performs object detection on image file
            $response = $imageAnnotator->objectLocalization($image);
            $objects = $response->getLocalizedObjectAnnotations();
            # performs color detection on image file
            $response = $imageAnnotator->imagePropertiesDetection($image);
            $imgProperties = $response->getImagePropertiesAnnotation();
            $colors = $imgProperties->getDominantColors()->getColors();

            //Create Media Entity
            $rel_path = str_replace("/sites/default/files/","/",$imgPath);
            $file = file_save_data($image, 'public://'.$rel_path.$fileName, FILE_EXISTS_REPLACE);
            $fileName = basename($filePath);
            $fileNameNoExt = substr($fileName, 0, strrpos($fileName, "."));
            $fileTitle = ucwords(str_replace('_', ' ', $fileNameNoExt));
            $media = Media::create([
                'bundle'           => 'image',
                'uid'              => \Drupal::currentUser()->id(),
                'field_media_image' => [
                  'target_id' => $file->id(),
                  'alt' => $fileTitle,
                  'title' => $fileTitle
                ],
            ]);

            if(!empty($labels)){
                foreach($labels as $label){
                    $label = $label->getDescription();
                    echo $label;
                    $terms = taxonomy_term_load_multiple_by_name($label, 'media_vision_labels');
                    $term = reset($terms);
                    if(empty($term)){
                        $new_term = Term::create([
                            'name' => $label,
                            'vid' => 'media_vision_labels'
                        ]);
                        $new_term->save();
                        $media->field_media_vision_labels[] = ['target_id' => $new_term->id()];
                    }
                    else{
                        $media->field_media_vision_labels[] = ['target_id' => $term->id()];
                    }
                }
            } 

            if(!empty($objects)){
                foreach($objects as $object){
                    $object = $object->getName();
                    echo $object;
                    $terms = taxonomy_term_load_multiple_by_name($object, 'media_vision_objects');
                    $term = reset($terms);
                    if(empty($term)){
                        $new_term = Term::create([
                            'name' => $object,
                            'vid' => 'media_vision_objects'
                        ]);
                        $new_term->save();
                        $media->field_media_vision_objects[] = ['target_id' => $new_term->id()];
                    }
                    else{
                        $media->field_media_vision_objects[] = ['target_id' => $term->id()];
                    }
                }
            }

            if(!empty($colors)){
                for($i = 0; $i<=2; $i++){
                    $color = empty($colors[$i]->getColor()) ? '' : $colors[$i]->getColor();
                    if(!empty($color)){
                        $color = $color->getRed(). ',' . $color->getGreen(). ',' . $color->getBlue();
                        echo $color;
                        $terms = taxonomy_term_load_multiple_by_name($color, 'media_vision_colors');
                        $term = reset($terms);
                        if(empty($term)){
                            $new_term = Term::create([
                                'name' => $color,
                                'vid' => 'media_vision_colors'
                            ]);
                            $new_term->save();
                            $media->field_media_vision_colors[] = ['target_id' => $new_term->id()];
                        }
                        else{
                            $media->field_media_vision_colors[] = ['target_id' => $term->id()];
                        }                        
                    }

                }
            }

            if(!empty($media)){
               $media->setName($fileName)->setPublished(TRUE)->save();
            }      
        }
    }
  }