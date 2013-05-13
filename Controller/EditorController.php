<?php

namespace ServerGrove\Bundle\TranslationEditorBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EditorController extends Controller
{
    private function getAllowLocale()
    {
        $user = $this->get('security.context')->getToken()->getUser();
        if (is_object($user) && preg_match('/^translator-(.+)$/', $user->getUsername(), $m)) {
            return $m[1];
        }
        else {
            throw new AccessDeniedHttpException();
        }
    }

    private function isCanEditAll()
    {
        return $this->getAllowLocale() == 'all';
    }

    private function isCanEdit($locale)
    {
        $allowLocale = $this->getAllowLocale();
        return $this->isCanEditAll() || ($allowLocale && $allowLocale == $locale);
    }

    public function getCollection()
    {
        return $this->container->get('server_grove_translation_editor.storage_manager')->getCollection();
    }

    public function listAction($to)
    {
        if (!$this->isCanEdit($to)) {
            return $this->redirect($this->generateUrl('sg_localeditor_list', array('to' => $this->getAllowLocale())));
        }

        $data = $this->getCollection()->find();

        $data->sort(array('locale' => 1));

        $locales = array();

        $default = $this->container->getParameter('locale', 'en');
        $missing = array();
		$duplicate = array();
		$groupped = array();

        foreach ($data as $d) {
            if ($to && !in_array($d['locale'], array($to, $default))) {
                continue;
            }
            if (!isset($locales[$d['locale']])) {
                $locales[$d['locale']] = array(
                    'entries' => array(),
                    'data'    => array()
                );
            }
            if (is_array($d['entries'])) {
                $locales[$d['locale']]['entries'] = array_merge($locales[$d['locale']]['entries'], $d['entries']);
                $locales[$d['locale']]['data'][$d['filename']] = $d;
            }
        }

        $localesList = array($default);
		foreach(array_keys($locales) as $l) {
			if ($l != $default) {
				$localesList[] = $l;
			}
		}

        $localesSorted = array();
		foreach ($locales[$default]['entries'] as $key => $val) {
			$keySplit = explode('.', rtrim($key, '.'), 2);
			$group = count($keySplit) > 1 ? $keySplit[0] : '';
			foreach ($localesList as $locale) {
				$localesSorted[$group][$key][$locale] = $locales[$locale]['entries'][$key];
				if (empty($locales[$locale]['entries'][$key])) {
					$missing[$key] = 1;
				}
				if ($locale != $default && $locales[$locale]['entries'][$key] == $locales[$default]['entries'][$key]) {
					$duplicate[$key] = 1;
				}
			}
		}

        return $this->render('ServerGroveTranslationEditorBundle:Editor:list.html.twig', array(
                'locales' => $localesSorted,
                'default' => $default,
                'missing' => $missing,
				'duplicate' => $duplicate,
				'localesList' => $localesList,
				'keysCount' => count($locales[$locale]['entries']),
                'canEditAll' => $this->isCanEditAll()
            )
        );
    }

    public function removeAction()
    {
        if (!$this->isCanEditAll()) {
            throw new AccessDeniedHttpException();
        }

        $request = $this->getRequest();

        if ($request->isXmlHttpRequest()) {
            $key = $request->request->get('key');

            $values = $this->getCollection()->find();

            foreach($values as $data) {
                if (isset($data['entries'][$key])) {
                    unset($data['entries'][$key]);
                    $this->updateData($data);
                }
            }

            $res = array(
                'result' => true,
            );
            return new \Symfony\Component\HttpFoundation\Response(json_encode($res));
        }
    }

    public function addAction()
    {
        if (!$this->isCanEditAll()) {
            throw new AccessDeniedHttpException();
        }

        $request = $this->getRequest();

        $locales = $request->request->get('locale');
        $key = $request->request->get('key');

        foreach($locales as $locale => $val ) {
            $values = $this->getCollection()->find(array('locale' => $locale));
            $values = iterator_to_array($values);
            if (!count($values)) {
                continue;
            }
            $found = false;
            foreach ($values as $data) {
                if (isset($data['entries'][$key])) {
                    $res = array(
                        'result' => false,
                        'msg' => 'The key already exists. Please update it instead.',
                    );
                    return new \Symfony\Component\HttpFoundation\Response(json_encode($res));
                }
            }

            $data = array_pop($values);

            $data['entries'][$key] = $val;

            if (!$request->request->get('check-only')) {
                $this->updateData($data);
            }
        }
        if ($request->isXmlHttpRequest()) {
            $res = array(
                'result' => true,
            );
            return new \Symfony\Component\HttpFoundation\Response(json_encode($res));
        }

        return new \Symfony\Component\HttpFoundation\RedirectResponse($this->generateUrl('sg_localeditor_list'));
    }

    public function updateAction()
    {
        $request = $this->getRequest();

        if ($request->isXmlHttpRequest()) {
            $locale = $request->request->get('locale');
            $key = $request->request->get('key');
            $val = $request->request->get('val');

            if (!$this->isCanEdit($locale)) {
                throw new AccessDeniedHttpException();
            }


            $values = $this->getCollection()->find(array('locale' => $locale));
            $values = iterator_to_array($values);

            $found = false;
            foreach ($values as $data) {
                if (isset($data['entries'][$key])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $data = array_pop($values);
            }

            $data['entries'][$key] = $val;
            $this->updateData($data);

            $res = array(
                'result' => true,
                'oldata' => $data['entries'][$key],

            );
            return new \Symfony\Component\HttpFoundation\Response(json_encode($res));
        }
    }

    protected function updateData($data)
    {
        $this->getCollection()->update(
            array('_id' => $data['_id'])
            , $data, array('upsert' => true));
    }
}
