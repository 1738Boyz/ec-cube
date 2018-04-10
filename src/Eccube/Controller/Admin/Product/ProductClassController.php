<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */


namespace Eccube\Controller\Admin\Product;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Eccube\Controller\AbstractController;
use Eccube\Entity\BaseInfo;
use Eccube\Entity\ClassName;
use Eccube\Entity\Master\SaleType;
use Eccube\Entity\Product;
use Eccube\Entity\ProductClass;
use Eccube\Entity\ProductStock;
use Eccube\Entity\TaxRule;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Form\Type\Admin\ProductClassType;
use Eccube\Repository\ClassCategoryRepository;
use Eccube\Repository\Master\SaleTypeRepository;
use Eccube\Repository\ProductClassRepository;
use Eccube\Repository\ProductRepository;
use Eccube\Repository\TaxRuleRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @Route(service=ProductClassController::class)
 */
class ProductClassController extends AbstractController
{
    /**
     * @var TaxRuleRepository
     */
    protected $taxRuleRepository;

    /**
     * @var SaleTypeRepository
     */
    protected $saleTypeRepository;

    /**
     * @var ClassCategoryRepository
     */
    protected $classCategoryRepository;

    /**
     * @var ProductClassRepository
     */
    protected $productClassRepository;

    /**
     * @var BaseInfo
     */
    protected $BaseInfo;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * ProductClassController constructor.
     *
     * @param TaxRuleRepository $taxRuleRepository
     * @param SaleTypeRepository $saleTypeRepository
     * @param ClassCategoryRepository $classCategoryRepository
     * @param ProductClassRepository $productClassRepository
     * @param BaseInfo $BaseInfo
     * @param ProductRepository $productRepository
     */
    public function __construct(
        TaxRuleRepository $taxRuleRepository,
        SaleTypeRepository $saleTypeRepository,
        ClassCategoryRepository $classCategoryRepository,
        ProductClassRepository $productClassRepository,
        BaseInfo $BaseInfo,
        ProductRepository $productRepository
    ) {
        $this->taxRuleRepository = $taxRuleRepository;
        $this->saleTypeRepository = $saleTypeRepository;
        $this->classCategoryRepository = $classCategoryRepository;
        $this->productClassRepository = $productClassRepository;
        $this->BaseInfo = $BaseInfo;
        $this->productRepository = $productRepository;
    }


    /**
     * 商品規格が登録されていなければ新規登録、登録されていれば更新画面を表示する
     *
     * @Route("/%eccube_admin_route%/product/product/class/{id}", requirements={"id" = "\d+"}, name="admin_product_product_class")
     * @Template("@admin/Product/product_class.twig")
     */
    public function index(Request $request, $id)
    {
        /** @var $Product \Eccube\Entity\Product */
        $Product = $this->productRepository->find($id);
        $hasClassCategoryFlg = false;

        if (!$Product) {
            throw new NotFoundHttpException(trans('product.text.error.product_not_found'));
        }

        // 商品規格情報が存在しなければ新規登録させる
        if (!$Product->hasProductClass()) {
            // 登録画面を表示

            log_info('商品規格新規登録表示', [$id]);

            $builder = $this->formFactory->createBuilder(FormType::class, null, [
                'allow_extra_fields' => true,
            ]);

            $builder
                ->add('class_name1', EntityType::class, [
                    'class' => 'Eccube\Entity\ClassName',
                    'choice_label' => 'backend_name',
                    'placeholder' => trans('product.placeholder.select_category01'),
                    'constraints' => [
                        new Assert\NotBlank(),
                    ],
                ])
                ->add('class_name2', EntityType::class, array(
                    'class' => 'Eccube\Entity\ClassName',
                    'choice_label' => 'backend_name',
                    'placeholder' => trans('product.placeholder.select_category02'),
                    'required' => false,
                ));

            $event = new EventArgs(
                [
                    'builder' => $builder,
                    'Product' => $Product,
                ],
                $request
            );
            $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_PRODUCT_CLASS_INDEX_INITIALIZE, $event);

            $form = $builder->getForm();

            $productClassForm = null;

            if ('POST' === $request->getMethod()) {

                $form->handleRequest($request);

                if ($form->isValid()) {

                    $data = $form->getData();

                    $ClassName1 = $data['class_name1'];
                    $ClassName2 = $data['class_name2'];

                    log_info('選択された商品規格', [$ClassName1, $ClassName2]);

                    // 各規格が選択されている際に、分類を保有しているか確認
                    $class1Valied = $this->isValiedCategory($ClassName1);
                    $class2Valied = $this->isValiedCategory($ClassName2);

                    // 規格が選択されていないか、選択された状態で分類が保有されていれば、画面表示
                    if($class1Valied && $class2Valied){
                        $hasClassCategoryFlg = true;
                    }

                    if (!is_null($ClassName2) && $ClassName1->getId() == $ClassName2->getId()) {
                        // 規格1と規格2が同じ値はエラー
                        $form['class_name2']->addError(new FormError(trans('product.text.error.same_value')));
                    } else {
                        // 規格分類が設定されていない商品規格を取得
                        $orgProductClasses = $Product->getProductClasses();
                        $sourceProduct = $orgProductClasses[0];

                        // 規格分類が組み合わされた商品規格を取得
                        $ProductClasses = $this->createProductClasses($Product, $ClassName1, $ClassName2);

                        // 組み合わされた商品規格にデフォルト値をセット
                        foreach ($ProductClasses as $productClass) {
                            $this->setDefaultProductClass($productClass, $sourceProduct);
                        }

                        $builder = $this->formFactory->createBuilder();

                        $builder
                            ->add('product_classes', CollectionType::class, [
                                'entry_type' => ProductClassType::class,
                                'allow_add' => true,
                                'allow_delete' => true,
                                'data' => $ProductClasses,
                             ]);

                        $event = new EventArgs(
                            [
                                'builder' => $builder,
                                'Product' => $Product,
                                'ProductClasses' => $ProductClasses,
                            ],
                            $request
                        );
                        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_PRODUCT_CLASS_INDEX_CLASSES, $event);

                        $productClassForm = $builder->getForm()->createView();

                    }

                }
            }

            return [
                'form' => $form->createView(),
                'classForm' => $productClassForm,
                'Product' => $Product,
                'not_product_class' => true,
                'error' => null,
                'has_class_category_flg' => $hasClassCategoryFlg,
            ];
        } else {
            // 既に商品規格が登録されている場合、商品規格画面を表示する

            log_info('商品規格登録済表示', [$id]);

            // 既に登録されている商品規格を取得
            $ProductClasses = $this->getProductClassesExcludeNonClass($Product);

            // 設定されている規格分類1、2を取得(商品規格の規格分類には必ず同じ値がセットされている)
            $ProductClass = $ProductClasses->first();
            $ClassName1 = $ProductClass->getClassCategory1()->getClassName();
            $ClassName2 = null;
            if (!is_null($ProductClass->getClassCategory2())) {
                $ClassName2 = $ProductClass->getClassCategory2()->getClassName();
            }

            // 規格分類が組み合わされた空の商品規格を取得
            $createProductClasses = $this->createProductClasses($Product, $ClassName1, $ClassName2);

            $mergeProductClasses = [];
            $sortProductClasses = new ArrayCollection();

            // 商品税率が設定されている場合、商品税率を項目に設定
            if ($this->BaseInfo->isOptionProductTaxRule())  {
                foreach ($ProductClasses as $class) {
                    if ($class->getTaxRule()) {
                        $class->setTaxRate($class->getTaxRule()->getTaxRate());
                    }
                }
            }

            // 登録済み商品規格と空の商品規格をマージ
            $flag = false;

            foreach ($createProductClasses as $index => $createProductClass) {
                // 既に登録済みの商品規格にチェックボックスを設定
                foreach ($ProductClasses as $productClass) {
                    if ($productClass->getClassCategory1() == $createProductClass->getClassCategory1() &&
                        $productClass->getClassCategory2() == $createProductClass->getClassCategory2()) {
                        // チェックボックスを追加
                        $productClass->setAdd(true);
                        $flag = true;
                        $sortProductClasses->add($productClass);
                        break;
                    }
                }

                if (!$flag) {
                    // 商品の中に規格ない場合は削除されている規格を取得する
                    $condition = array();
                    $condition['Product'] = $Product;
                    $condition['ClassCategory1'] = $createProductClass->getClassCategory1();
                    $condition['ClassCategory2'] = $createProductClass->getClassCategory2();
                    $condition['visible'] = false;

                    $delProductClass = $this->productClassRepository->findOneBy($condition);
                    if ($delProductClass){
                        // 削除されている規格あった場合はその規格を使う
                        $mergeProductClasses[] = $delProductClass;
                    } else {
                        // 取得できない場合はデフォルトの規格を使う
                        $mergeProductClasses[] = $createProductClass;
                    }
                }

                $flag = false;
            }

            // 登録済み商品規格と空の商品規格をマージ
            foreach ($mergeProductClasses as $mergeProductClass) {
                // 空の商品規格にデフォルト値を設定
                $this->setDefaultProductClass($mergeProductClass, $sortProductClasses[0]);
                $sortProductClasses->add($mergeProductClass);
            }

            $builder = $this->formFactory->createBuilder();

            $builder
                ->add('product_classes', CollectionType::class, [
                    'entry_type' => ProductClassType::class,
                    'allow_add' => true,
                    'allow_delete' => true,
                    'data' => $sortProductClasses,
                ]);

            $event = new EventArgs(
                [
                    'builder' => $builder,
                    'Product' => $Product,
                    'ProductClasses' => $sortProductClasses,
                ],
                $request
            );
            $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_PRODUCT_CLASS_INDEX_CLASSES, $event);

            $productClassForm = $builder->getForm()->createView();

            return [
                'classForm' => $productClassForm,
                'Product' => $Product,
                'class_name1' => $ClassName1,
                'class_name2' => $ClassName2,
                'not_product_class' => false,
                'error' => null,
                'has_class_category_flg' => true,
            ];
        }
    }

    /**
     * 商品規格の登録、更新、削除を行う
     *
     * @Route("/%eccube_admin_route%/product/product/class/edit/{id}", requirements={"id" = "\d+"}, name="admin_product_product_class_edit")
     * @Template("@admin/Product/product_class.twig")
     *
     * @param Request     $request
     * @param int         $id
     * @return RedirectResponse
     */
    public function edit(Request $request, $id)
    {
        /** @var $Product \Eccube\Entity\Product */
        $Product = $this->productRepository->find($id);

        if (!$Product) {
            throw new NotFoundHttpException(trans('product.text.error.product_not_found'));
        }

        /* @var FormBuilder $builder */
        $builder = $this->formFactory->createBuilder(FormType::class, null, [
            'allow_extra_fields' => true,
        ]);
        $builder->add('product_classes', CollectionType::class, array(
                    'entry_type' => ProductClassType::class,
                    'allow_add' => true,
                    'allow_delete' => true,
        ));

        $event = new EventArgs(
            array(
                'builder' => $builder,
                'Product' => $Product,
            ),
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_PRODUCT_CLASS_EDIT_INITIALIZE, $event);

        $form = $builder->getForm();

        $ProductClasses = $this->getProductClassesExcludeNonClass($Product);

        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            switch ($request->get('mode')) {
                case 'edit':
                    // 新規登録
                    log_info('商品規格新規登録開始', array($id));

                    if (count($ProductClasses) > 0) {
                        // 既に登録されていれば最初の画面に戻す
                        log_info('商品規格登録済', array($id));
                        return $this->redirectToRoute('admin_product_product_class', array('id' => $id));
                    }

                    $addProductClasses = array();

                    $tmpProductClass = null;
                    foreach ($form->get('product_classes') as $formData) {
                        // 追加対象の行をvalidate
                        $ProductClass = $formData->getData();

                        if ($ProductClass->getAdd()) {
                            if ($formData->isValid()) {
                                $addProductClasses[] = $ProductClass;
                            } else {
                                // 対象行のエラー
                                return $this->renderError($Product, $ProductClass, true, $form);
                            }
                        }
                        $tmpProductClass = $ProductClass;
                    }

                    if (count($addProductClasses) == 0) {
                        // 対象がなければエラー
                        log_info('商品規格が未選択', array($id));
                        $error = array('message' => trans('product.text.error.category_not_selected'));
                        return $this->renderError($Product, $tmpProductClass, true, $form, $error);
                    }

                    // 選択された商品規格を登録
                    $this->insertProductClass($Product, $addProductClasses);

                    // デフォルトの商品規格を非表示
                    /** @var ProductClass $defaultProductClass */
                    $defaultProductClass = $this->productClassRepository
                            ->findOneBy(array('Product' => $Product, 'ClassCategory1' => null, 'ClassCategory2' => null));
                    $defaultProductClass->setVisible(false);

                    $this->entityManager->flush();

                    log_info('商品規格新規登録完了', array($id));

                    $event = new EventArgs(
                        array(
                            'form' => $form,
                            'Product' => $Product,
                            'defaultProductClass' => $defaultProductClass,
                        ),
                        $request
                    );
                    $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_PRODUCT_CLASS_EDIT_COMPLETE, $event);

                    $this->addSuccess('admin.product.product_class.save.complete', 'admin');

                    break;
                case 'update':
                    // 更新
                    log_info('商品規格更新開始', array($id));

                    if (count($ProductClasses) == 0) {
                        // 商品規格が0件であれば最初の画面に戻す
                        log_info('商品規格が存在しません', array($id));
                        return $this->redirectToRoute('admin_product_product_class', array('id' => $id));
                    }

                    $checkProductClasses = array();
                    $removeProductClasses = array();

                    $tempProductClass = null;
                    foreach ($form->get('product_classes') as $formData) {
                        // 追加対象の行をvalidate
                        $ProductClass = $formData->getData();

                        if ($ProductClass->getAdd()) {
                            if ($formData->isValid()) {
                                $checkProductClasses[] = $ProductClass;
                            } else {
                                return $this->renderError($Product, $ProductClass, false, $form);
                            }
                        } else {
                            // 削除対象の行
                            $removeProductClasses[] = $ProductClass;
                        }
                        $tempProductClass = $ProductClass;
                    }

                    if (count($checkProductClasses) == 0) {
                        // 対象がなければエラー
                        log_info('商品規格が存在しません', array($id));
                        $error = array('message' => trans('product.text.error.category_not_selected'));
                        return $this->renderError($Product, $tempProductClass, false, $form, $error);
                    }

                    // 登録対象と更新対象の行か判断する
                    $addProductClasses = array();
                    $updateProductClasses = array();
                    foreach ($checkProductClasses as $cp) {
                        $flag = false;

                        // 既に登録済みの商品規格か確認
                        foreach ($ProductClasses as $productClass) {
                            if ($productClass->getProduct()->getId() == $id &&
                                $productClass->getClassCategory1() == $cp->getClassCategory1() &&
                                $productClass->getClassCategory2() == $cp->getClassCategory2()) {
                                $updateProductClasses[] = $cp;

                                // 商品情報
                                $cp->setProduct($Product);
                                // 商品在庫
                                $productStock = $productClass->getProductStock();
                                if (!$cp->isStockUnlimited()) {
                                    $productStock->setStock($cp->getStock());
                                } else {
                                    $productStock->setStock(null);
                                }
                                $this->setDefaultProductClass($productClass, $cp);
                                $flag = true;
                                break;
                            }
                        }

                        if (!$flag) {
                            // 削除されている規格取得する
                            $condition = array();
                            $condition['Product'] = $Product;
                            $condition['ClassCategory1'] = $cp->getClassCategory1();
                            $condition['ClassCategory2'] = $cp->getClassCategory2();
                            $condition['visible'] = false;

                            $delProductClass = $this->productClassRepository->findOneBy($condition);
                            if ($delProductClass){
                                // 削除されている商品を見つけた場合は使います
                                // 商品情報
                                $cp->setProduct($Product);
                                // 商品在庫
                                $productStock = $productClass->getProductStock();
                                if (!$cp->getStockUnlimited()) {
                                    $productStock->setStock($cp->getStock());
                                } else {
                                    $productStock->setStock(null);
                                }
                                $this->setDefaultProductClass($delProductClass, $cp);
                                // 削除されている規格を復活する（visibleを更新）
                                $delProductClass->setVisible(false);
                                $ProductClasses[] = $delProductClass;
                            } else {
                                $addProductClasses[] = $cp;
                            }
                        }
                    }

                    foreach ($removeProductClasses as $rc) {
                        // 登録されている商品規格を非表示
                        /** @var ProductClass $productClass */
                        foreach ($ProductClasses as $productClass) {
                            if ($productClass->getProduct()->getId() == $id &&
                                $productClass->getClassCategory1() == $rc->getClassCategory1() &&
                                $productClass->getClassCategory2() == $rc->getClassCategory2()) {
                                $productClass->setVisible(false);
                                break;
                            }
                        }
                    }

                    // 選択された商品規格を登録
                    $this->insertProductClass($Product, $addProductClasses);

                    $this->entityManager->flush();

                    log_info('商品規格更新完了', array($id));

                    $event = new EventArgs(
                        array(
                            'form' => $form,
                            'Product' => $Product,
                            'updateProductClasses' => $updateProductClasses,
                            'addProductClasses' => $addProductClasses,
                        ),
                        $request
                    );
                    $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_PRODUCT_CLASS_EDIT_UPDATE, $event);

                    $this->addSuccess('admin.product.product_class.update.complete', 'admin');

                    break;

                case 'delete':
                    // 削除
                    log_info('商品規格削除開始', array($id));

                    if (count($ProductClasses) == 0) {
                        // 既に商品が削除されていれば元の画面に戻す
                        log_info('商品規格が存在しません', array($id));
                        return $this->redirectToRoute('admin_product_product_class', array('id' => $id));
                    }

                    foreach ($ProductClasses as $ProductClass) {
                        // 登録されている商品規格を非表示
                        $ProductClass->setVisible(false);
                    }

                    // デフォルトの商品規格を表示
                    /** @var ProductClass $defaultProductClass */

                    $defaultProductClass = $this->productClassRepository
                            ->findOneBy(array('Product' => $Product, 'ClassCategory1' => null, 'ClassCategory2' => null, 'visible' => false));
                    $defaultProductClass->setVisible(true);

                    $this->entityManager->flush();
                    log_info('商品規格削除完了', array($id));

                    $event = new EventArgs(
                        array(
                            'form' => $form,
                            'Product' => $Product,
                            'defaultProductClass' => $defaultProductClass,
                        ),
                        $request
                    );
                    $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_PRODUCT_PRODUCT_CLASS_EDIT_DELETE, $event);

                    $this->addSuccess('admin.product.product_class.delete.complete', 'admin');

                    break;
                default:
                    break;
            }

        }

        return $this->redirectToRoute('admin_product_product_class', array('id' => $id));
    }

    /**
     * 登録、更新時のエラー画面表示
     *
     * @param \Eccube\Entity\Product $Product
     * @param \Eccube\Entity\ProductClass $ProductClass
     * @param \Symfony\Component\HttpFoundation\Response $not_product_class
     * @param FormInterface $classForm
     * @param array $error
     * @return array
     */
    protected function renderError($Product, $ProductClass, $not_product_class, $classForm, $error = null)
    {

        $ClassName1 = null;
        $ClassName2 = null;
        // 規格を取得
        if (isset($ProductClass)) {
            $ClassCategory1 = $ProductClass->getClassCategory1();
            if ($ClassCategory1) {
                $ClassName1 = $ClassCategory1->getClassName();
            }
            $ClassCategory2 = $ProductClass->getClassCategory2();
            if ($ClassCategory2) {
                $ClassName2 = $ClassCategory2->getClassName();
            }
        }

        /* @var FormBuilder $builder */
        $form = $this->formFactory->createBuilder()
            ->add('class_name1', EntityType::class, array(
                'class' => 'Eccube\Entity\ClassName',
                'choice_label' => 'backend_name',
                'placeholder' => trans('product.placeholder.select_category01'),
                'data' => $ClassName1,
            ))
            ->add('class_name2', EntityType::class, array(
                'class' => 'Eccube\Entity\ClassName',
                'choice_label' => 'backend_name',
                'placeholder' => trans('product.placeholder.select_category02'),
                'data' => $ClassName2,
            ))
            ->getForm();

        log_info('商品規格登録エラー');


        return [
            'form' => $form->createView(),
            'classForm' => $classForm->createView(),
            'Product' => $Product,
            'class_name1' => $ClassName1,
            'class_name2' => $ClassName2,
            'not_product_class' => $not_product_class,
            'error' => $error,
            'has_class_category_flg' => true,
        ];
    }


    /**
     * 規格1と規格2を組み合わせた商品規格を作成
     */
    private function createProductClasses( Product $Product, ClassName $ClassName1 = null, ClassName $ClassName2 = null)
    {

        $ClassCategories1 = array();
        if ($ClassName1) {
            $ClassCategories1 = $this->classCategoryRepository->findBy(['ClassName' => $ClassName1], ['sort_no' => 'DESC']);
        }

        $ClassCategories2 = array();
        if ($ClassName2) {
            $ClassCategories2 = $this->classCategoryRepository->findBy(['ClassName' => $ClassName2], ['sort_no' => 'DESC']);
        }

        $ProductClasses = array();
        foreach ($ClassCategories1 as $ClassCategory1) {
            if ($ClassCategories2) {
                foreach ($ClassCategories2 as $ClassCategory2) {
                    $ProductClass = $this->newProductClass();
                    $ProductClass->setProduct($Product);
                    $ProductClass->setClassCategory1($ClassCategory1);
                    $ProductClass->setClassCategory2($ClassCategory2);
                    $ProductClass->setTaxRate(null);
                    $ProductClass->setVisible(true);
                    $ProductClasses[] = $ProductClass;
                }
            } else {
                $ProductClass = $this->newProductClass();
                $ProductClass->setProduct($Product);
                $ProductClass->setClassCategory1($ClassCategory1);
                $ProductClass->setTaxRate(null);
                $ProductClass->setVisible(true);
                $ProductClasses[] = $ProductClass;
            }

        }
        return $ProductClasses;
    }

    /**
     * 新しい商品規格を作成
     */
    private function newProductClass()
    {
        $SaleType = $this->saleTypeRepository->find(SaleType::SALE_TYPE_NORMAL);

        $ProductClass = new ProductClass();
        $ProductClass->setSaleType($SaleType);
        return $ProductClass;
    }

    /**
     * 商品規格のコピーを取得.
     *
     * @see http://symfony.com/doc/current/cookbook/form/form_collections.html
     * @param Product $Product
     * @return \Eccube\Entity\ProductClass[]
     */
    private function getProductClassesOriginal(Product $Product)
    {
        $ProductClasses = $Product->getProductClasses();
        return $ProductClasses->filter(function($ProductClass) {
            return true;
        });
    }

    /**
     * 規格なし商品を除いて商品規格を取得.
     *
     * @param Product $Product
     * @return Collection
     */
    private function getProductClassesExcludeNonClass(Product $Product)
    {
        $ProductClasses = $Product->getProductClasses();
        return new ArrayCollection(array_values($ProductClasses->filter(function($ProductClass) {
            $ClassCategory1 = $ProductClass->getClassCategory1();
            $ClassCategory2 = $ProductClass->getClassCategory2();
            return ($ClassCategory1 || $ClassCategory2);
        })->toArray()));
    }

    /**
     * デフォルトとなる商品規格を設定
     *
     * @param $productClassDest ProductClass コピー先となる商品規格
     * @param $productClassOrig ProductClass コピー元となる商品規格
     */
    private function setDefaultProductClass($productClassDest, $productClassOrig)
    {
        $productClassDest->setDeliveryDuration($productClassOrig->getDeliveryDuration());
        $productClassDest->setProduct($productClassOrig->getProduct());
        $productClassDest->setSaleType($productClassOrig->getSaleType());
        $productClassDest->setCode($productClassOrig->getCode());
        $productClassDest->setStock($productClassOrig->getStock());
        $productClassDest->setStockUnlimited($productClassOrig->isStockUnlimited());
        $productClassDest->setSaleLimit($productClassOrig->getSaleLimit());
        $productClassDest->setPrice01($productClassOrig->getPrice01());
        $productClassDest->setPrice02($productClassOrig->getPrice02());
        $productClassDest->setDeliveryFee($productClassOrig->getDeliveryFee());

        // 個別消費税
        if ($this->BaseInfo->isOptionProductTaxRule()) {
            if ($productClassOrig->getTaxRate() !== false && $productClassOrig->getTaxRate() !== null) {
                $productClassDest->setTaxRate($productClassOrig->getTaxRate());
                if ($productClassDest->getTaxRule()) {
                    $productClassDest->getTaxRule()->setTaxRate($productClassOrig->getTaxRate());
                } else {
                    $taxrule = $this->taxRuleRepository->newTaxRule();
                    $taxrule->setTaxRate($productClassOrig->getTaxRate());
                    $taxrule->setApplyDate(new \DateTime());
                    $taxrule->setProduct($productClassDest->getProduct());
                    $taxrule->setProductClass($productClassDest);
                    $productClassDest->setTaxRule($taxrule);
                }
            } else {
                if ($productClassDest->getTaxRule()) {
                    $this->taxRuleRepository->delete($productClassDest->getTaxRule());
                    $productClassDest->setTaxRule(null);
                }
            }
        }
    }


    /**
     * 商品規格を登録
     *
     * @param Product         $Product
     * @param ProductClass[] $ProductClasses 登録される商品規格
     */
    private function insertProductClass($Product, $ProductClasses) {


        // 選択された商品を登録
        foreach ($ProductClasses as $ProductClass) {

            $ProductClass->setVisible(true);
            $ProductClass->setProduct($Product);
            $this->entityManager->persist($ProductClass);

            // 在庫情報を作成
            $ProductStock = new ProductStock();
            $ProductClass->setProductStock($ProductStock);
            $ProductStock->setProductClass($ProductClass);
            if (!$ProductClass->isStockUnlimited()) {
                $ProductStock->setStock($ProductClass->getStock());
            } else {
                // 在庫無制限時はnullを設定
                $ProductStock->setStock(null);
            }
            $this->entityManager->persist($ProductStock);

        }

        // 商品税率が設定されている場合、商品税率をセット
        if ($this->BaseInfo->isOptionProductTaxRule()) {
            // 初期設定の税設定.
            $TaxRule = $this->taxRuleRepository->find(TaxRule::DEFAULT_TAX_RULE_ID);
            // 初期税率設定の計算方法を設定する
            $RoundingType = $TaxRule->getRoundingType();
            foreach ($ProductClasses as $ProductClass) {
                if ($ProductClass && is_numeric($taxRate = $ProductClass->getTaxRate())) {
                    $TaxRule = new TaxRule();
                    $TaxRule->setProduct($Product);
                    $TaxRule->setProductClass($ProductClass);
                    $TaxRule->setRoundingType($RoundingType);
                    $TaxRule->setTaxRate($taxRate);
                    $TaxRule->setTaxAdjust(0);
                    $TaxRule->setApplyDate(new \DateTime());
                    $this->entityManager->persist($TaxRule);
                }
            }
        }

    }

    /**
     * 規格の分類判定
     *
     * @param $class_name
     * @return boolean
     */
    private function isValiedCategory($class_name)
    {
        if (empty($class_name)) {
            return true;
        }
        if (count($class_name->getClassCategories()) < 1) {
            return false;
        }
        return true;
    }
}
