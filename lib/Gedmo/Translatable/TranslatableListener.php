<?php

namespace Gedmo\Translatable;

use Doctrine\Common\EventArgs;
use Gedmo\Mapping\MappedEventSubscriber;
use Gedmo\Mapping\ObjectManagerHelper as OMH;
use Gedmo\Translatable\Mapping\Event\TranslatableAdapterInterface;

/**
 * Translatable listener handles the generation and
 * loading of translations for orm entities and mongo odm documents
 *
 * This behavior can impact the performance of your application
 * since it does an additional query for each field to translate.
 * Translations can be preloaded with translations collection.
 *
 * Nevertheless the annotation metadata is properly cached and
 * it is not a big overhead to lookup all entity annotations since
 * the caching is activated for metadata
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class TranslatableListener extends MappedEventSubscriber
{
    /**
     * Query hint to override the fallback locales of translations
     * array of locales to fallback, first in array gets priority
     */
    const HINT_FALLBACK = 'gedmo.translatable.fallback';

    /**
     * Query hint to override the fallback locale
     */
    const HINT_TRANSLATABLE_LOCALE = 'gedmo.translatable.locale';

    /**
     * Query hint to use inner join strategy for translations
     */
    const HINT_INNER_JOIN = 'gedmo.translatable.inner_join.translations';

    /**
     * Locale which is set on this listener.
     * If Entity being translated has locale defined it
     * will override this one
     *
     * @var string
     */
    protected $locale = 'en';

    /**
     * If this is set to false, when if entity does
     * not have a translation for requested locale
     * it will show a blank value
     *
     * @var array
     */
    protected $translationFallbackLocales = array();

    /**
     * Currently in case if there is TranslationQueryWalker
     * in charge. We need to skip issuing additional queries
     * on load
     *
     * @var boolean
     */
    private $skipOnLoad = false;

    /**
     * Specifies the list of events to listen
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            'postLoad',
            'onFlush',
            'loadClassMetadata',
            'postPersist', // track changes done by other behaviors
            'postUpdate', // track changes done by other behaviors
        );
    }

    /**
     * Set the locale to use for translation listener
     *
     * @param string $locale
     *
     * @return static
     */
    public function setTranslatableLocale($locale)
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Get currently set global locale, used extensively during query execution
     *
     * @return string
     */
    public function getTranslatableLocale()
    {
        return $this->locale;
    }

    /**
     * Set list of translation fallback locales
     * Will be active only for onLoad
     *
     * @param array $fallbackLocales
     *
     * @return static
     */
    public function setFallbackLocales(array $fallbackLocales)
    {
        $this->translationFallbackLocales = $fallbackLocales;

        return $this;
    }

    /**
     * Get translation fallback locale list, currently set
     *
     * @return array
     */
    public function getFallbackLocales()
    {
        return $this->translationFallbackLocales;
    }

    /**
     * Set to skip or not onLoad event
     *
     * @param boolean $bool
     *
     * @return static
     */
    public function setSkipOnLoad($bool)
    {
        $this->skipOnLoad = (bool) $bool;

        return $this;
    }

    /**
     * Maps additional metadata
     *
     * @param EventArgs $eventArgs
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $eventArgs->getClassMetadata());
    }

    /**
     * Looks for translatable objects being inserted or updated
     * for further processing
     *
     * @param EventArgs $args
     */
    public function onFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        // check all scheduled inserts for Translatable objects
        foreach ($ea->getScheduledObjectInsertions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if (($config = $this->getConfiguration($om, $meta->name)) && isset($config['fields'])) {
                $this->persistNewTranslation($ea, $object);
            }
        }
        // check all scheduled updates for Translatable entities
        foreach ($ea->getScheduledObjectUpdates($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if (($config = $this->getConfiguration($om, $meta->name)) && isset($config['fields'])) {
                $this->updateTranslation($ea, $object);
            }
        }
    }

    /**
     * Does extra checks for flushed translations
     * if there were any changes done to them
     *
     * @param EventArgs $event
     */
    public function postPersist(EventArgs $event)
    {
        $this->postTranslationChanges($event);
    }

    /**
     * Does extra checks for flushed translations
     * if there were any changes done to them
     *
     * @param EventArgs $event
     */
    public function postUpdate(EventArgs $event)
    {
        $this->postTranslationChanges($event);
    }

    /**
     * Does extra checks for flushed translations
     * if there were any changes done to them
     *
     * @param EventArgs $event
     */
    protected function postTranslationChanges($event)
    {
        $om = OMH::getObjectManagerFromEvent($event);
        $object = OMH::getObjectFromEvent($event);

        // we are interested only to changes for translations
        if ($object instanceof TranslationInterface) {
            $tmeta = $om->getClassMetadata(get_class($object));
            // check for slugs
            if (isset(self::$configurations['Sluggable'][$tmeta->name])) {
                if ($config = self::$configurations['Sluggable'][$tmeta->name]) {
                    // there was a translated slug, update back the translated object
                    $translated = $tmeta->getReflectionProperty('object')->getValue($object);
                    $meta = $om->getClassMetadata(get_class($translated));
                    $changeSet = array();
                    foreach ($config['slugs'] as $slugField => $options) {
                        $slugProp = $meta->getReflectionProperty($slugField);
                        $changeSet[$slugField] = array(
                            $slugProp->getValue($translated), // old slug
                            $newSlug = $tmeta->getReflectionProperty($slugField)->getValue($object),
                        );
                        $slugProp->setValue($translated, $newSlug);
                        OMH::setOriginalObjectProperty($om->getUnitOfWork(), spl_object_hash($translated), $slugField, $newSlug);
                    }
                    $om->getUnitOfWork()->scheduleExtraUpdate($translated, $changeSet);
                }
            }
        }
    }

    /**
     * Shedules translation update for $object in persisted locale
     *
     * @param TranslatableAdapterInterface $ea
     * @param object                       $object
     */
    protected function updateTranslation(TranslatableAdapterInterface $ea, $object)
    {
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $meta = $om->getClassMetadata(get_class($object));
        $config = $this->getConfiguration($om, $meta->name);
        $tmeta = $om->getClassMetadata($config['translationClass']);

        if (!$translation = $ea->findTranslation($object, $this->locale, $config['translationClass'])) {
            $this->persistNewTranslation($ea, $object);
        } else {
            $changeSet = OMH::getObjectChangeSet($uow, $object);
            foreach ($config['fields'] as $field => $options) {
                // translate only those fields, which have changed, otherwise translation value is default
                // if a translation in different language is the same. Persist translation manually in collection
                if (array_key_exists($field, $changeSet)) {
                    $prop = $meta->getReflectionProperty($field);
                    $tprop = $tmeta->getReflectionProperty($field);
                    $tprop->setValue($translation, $prop->getValue($object));
                }
            }
            $om->persist($translation);
            $uow->computeChangeSet($tmeta, $translation);
        }
    }

    /**
     * Persists a new translation for $object
     *
     * @param TranslatableAdapterInterface $ea
     * @param object                       $object
     */
    protected function persistNewTranslation(TranslatableAdapterInterface $ea, $object)
    {
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $meta = $om->getClassMetadata(get_class($object));
        $config = $this->getConfiguration($om, $meta->name);
        $tmeta = $om->getClassMetadata($config['translationClass']);

        // ensure all translatable fields are available on translation class
        foreach ($config['fields'] as $field => $options) {
            if (!$tmeta->hasField($field)) {
                throw new InvalidMappingException("Translation {$config['translationClass']} does not have a translated field '{$field}' mapped"
                    .". Run the command to regenerate/update translations or update it manually"
                );
            }
        }
        // check if translation was manually added into collection
        if ($translations = $ea->getTranslationCollection($object, $config['translationClass'])) {
            foreach ($translations as $translation) {
                if ($translation->getLocale() === $this->locale) {
                    // need to update object properties
                    foreach ($config['fields'] as $field => $options) {
                        $prop = $meta->getReflectionProperty($field);
                        $tprop = $tmeta->getReflectionProperty($field);
                        $prop->setValue($object, $tprop->getValue($translation));
                        $ea->recomputeSingleObjectChangeSet($uow, $meta, $object);
                    }

                    return; // already added by user
                }
            }
        }

        $translation = new $config['translationClass']();
        $translation->setObject($object);
        $translation->setLocale($this->locale);
        $changeSet = OMH::getObjectChangeSet($uow, $object);

        foreach ($config['fields'] as $field => $options) {
            // translate only those fields, which have changed, otherwise translation value is default
            // if a translation in different language is the same. Persist translation manually in collection
            if (array_key_exists($field, $changeSet)) {
                $prop = $meta->getReflectionProperty($field);
                $tprop = $tmeta->getReflectionProperty($field);
                $tprop->setValue($translation, $prop->getValue($object));
            }
        }

        $om->persist($translation);
        $uow->computeChangeSet($tmeta, $translation);

        // ensure translation will be there in collection
        if ($translations = $this->getTranslationCollection($om, $object, $config['translationClass'])) {
            if (!$translations->contains($translation)) {
                $translations->add($translation);
            }
        }
    }

    /**
     * After object is loaded, listener updates the translations
     * by currently used locale
     *
     * @param EventArgs $args
     */
    public function postLoad(EventArgs $args)
    {
        if ($this->skipOnLoad) {
            return;
        }

        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));

        if (($config = $this->getConfiguration($om, $meta->name)) && isset($config['fields'])) {
            if (!$translation = $ea->findTranslation($object, $this->locale, $config['translationClass'])) {
                // try fallback to specified locales
                $fallbackLocales = $this->translationFallbackLocales;
                while ($fallback = array_shift($fallbackLocales)) {
                    if ($translation = $ea->findTranslation($object, $fallback, $config['translationClass'])) {
                        break;
                    }
                }
            }
            $oid = spl_object_hash($object);

            // if there was a translation available, translate an entity
            if ($translation) {
                $tmeta = $om->getClassMetadata($config['translationClass']);
                foreach ($config['fields'] as $field => $options) {
                    $prop = $meta->getReflectionProperty($field);
                    $tprop = $tmeta->getReflectionProperty($field);
                    $prop->setValue($object, $value = $tprop->getValue($translation));
                    $ea->setOriginalObjectProperty($om->getUnitOfWork(), $oid, $field, $value);
                }
            } else {
                // if there was no fallback or current translation, null values
                foreach ($config['fields'] as $field => $options) {
                    $prop = $meta->getReflectionProperty($field);
                    $prop->setValue($object, null); // consider getting new object instance and use default values
                    $ea->setOriginalObjectProperty($om->getUnitOfWork(), $oid, $field, null);
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }
}
