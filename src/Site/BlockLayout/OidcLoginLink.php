<?php
namespace Oidc\Site\BlockLayout;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;

class OidcLoginLink extends AbstractBlockLayout
{
    public function getLabel()
    {
        return 'OIDC login link'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        $data = $block ? $block->data() : [];

        $form = new Form();
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][label]',
            'type' => Element\Text::class,
            'options' => ['label' => 'Button label'], // @translate
            'attributes' => ['value' => $data['label'] ?? 'Log in with CILogon'],
        ]);
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][show_local_link]',
            'type' => Element\Checkbox::class,
            'options' => ['label' => 'Show local-login link alongside'], // @translate
            'attributes' => ['value' => !empty($data['show_local_link']) ? '1' : '0'],
        ]);
        $form->prepare();

        return $view->formCollection($form, false);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $data = $block->data();
        return $view->oidcLoginLink([
            'label' => $data['label'] ?? 'Log in with CILogon',
            'showLocalLink' => !empty($data['show_local_link']),
        ]);
    }
}
