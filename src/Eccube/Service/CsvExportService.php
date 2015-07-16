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


namespace Eccube\Service;

use Eccube\Common\Constant;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;

class CsvExportService
{
    /**
     * @var
     */
    protected $fp;

    /**
     * @var
     */
    protected $closed = false;

    /**
     * @var \Closure
     */
    protected $convertEncodingCallBack;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $em;

    /**
     * @var \Doctrine\ORM\QueryBuilder;
     */
    protected $qb;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var \Eccube\Entity\Master\CsvType
     */
    protected $CsvType;

    /**
     * @var \Eccube\Entity\Csv[]
     */
    protected $Csvs;

    /**
     * @var \Eccube\Repository\CsvRepository
     */
    protected $csvRepository;

    /**
     * @var \Eccube\Repository\Master\CsvTypeRepository
     */
    protected $csvTypeRepository;

    /**
     * @var \Eccube\Repository\OrderRepository
     */
    protected $orderRepository;

    /**
     * @var \Eccube\Repository\CustomerRepository
     */
    protected $customerRepository;

    /**
     * @var \Eccube\Repository\ProductRepository
     */
    protected $productRepository;

    /**
     * @param $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @param \Eccube\Repository\CsvRepository $csvRepository
     */
    public function setCsvRepository(\Eccube\Repository\CsvRepository $csvRepository)
    {
        $this->csvRepository = $csvRepository;
    }

    /**
     * @param \Eccube\Repository\Master\CsvTypeRepository $csvTypeRepository
     */
    public function setCsvTypeRepository(\Eccube\Repository\Master\CsvTypeRepository $csvTypeRepository)
    {
        $this->csvTypeRepository = $csvTypeRepository;
    }

    /**
     * @param \Eccube\Repository\OrderRepository $orderRepository
     */
    public function setOrderRepository(\Eccube\Repository\OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param \Eccube\Repository\CustomerRepository $customerRepository
     */
    public function setCustomerRepository(\Eccube\Repository\CustomerRepository $customerRepository)
    {
        $this->customerRepository = $customerRepository;
    }

    /**
     * @param \Eccube\Repository\ProductRepository $productRepository
     */
    public function setProductRepository(\Eccube\Repository\ProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    /**
     * @param \Doctrine\ORM\EntityManager $em
     */
    public function setEntityManager(\Doctrine\ORM\EntityManager $em)
    {
        $this->em = $em;
    }

    /**
     * @return \Doctrine\ORM\EntityManager
     */
    public function getEntityManager()
    {
        return $this->em;
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $qb
     */
    public function setExportQueryBuilder(\Doctrine\ORM\QueryBuilder $qb)
    {
        $this->qb = $qb;
        $this->setEntityManager($qb->getEntityManager());
    }

    /**
     * Csv��ʂ���Service�̏��������s��.
     *
     * @param $CsvType|integer
     */
    public function initCsvType($CsvType)
    {
        if ($CsvType instanceof \Eccube\Entity\Master\CsvType) {
            $this->CsvType = $CsvType;
        } else {
            $this->CsvType = $this->csvTypeRepository->find($CsvType);
        }

        $criteria = array(
            'CsvType' => $CsvType,
            'enable_flg' => Constant::ENABLED
        );
        $orderBy = array(
            'rank' => 'ASC'
        );
        $this->Csvs = $this->csvRepository->findBy($criteria, $orderBy);
    }

    /**
     * @return \Eccube\Entity\Csv[]
     */
    public function getCsvs()
    {
        return $this->Csvs;
    }

    /**
     * �w�b�_�s���o�͂���.
     * ���̃��\�b�h���g���ꍇ��, ���O��initCsvType($CsvType)�ŏ��������Ă����K�v������.
     */
    public function exportHeader()
    {
        if (is_null($this->CsvType) || is_null($this->Csvs)) {
            throw new \LogicException('init csv type incomplete.');
        }

        $row = array();
        foreach ($this->Csvs as $Csv) {
            $row[] = $Csv->getDispName();
        }

        $this->fopen();
        $this->fputcsv($row);
        $this->fclose();
    }

    /**
     * �N�G���r���_�ɂ��ƂÂ��ăf�[�^�s���o�͂���.
     * ���̃��\�b�h���g���ꍇ��, ���O��setExportQueryBuilder($qb)�ŏo�͑Ώۂ̃N�G���r���_���킽���Ă����K�v������.
     *
     * @param \Closure $closure
     */
    public function exportData(\Closure $closure)
    {
        if (is_null($this->qb) || is_null($this->em)) {
            throw new \LogicException('query builder not set.');
        }

        $this->fopen();

        $query = $this->qb->getQuery();
        foreach ($query->iterate() as $iteratableResult) {
            $closure($iteratableResult[0], $this);

            $this->em->detach($iteratableResult[0]);
            $this->em->clear();
            $query->free();
            flush();
        }

        $this->fclose();
    }

    /**
     * CSV�o�͍��ڂƔ�r��, ���v����f�[�^��Ԃ�.
     *
     * @param \Eccube\Entity\Csv $Csv
     * @param $entity
     * @return mixed|null|string|void
     */
    public function getData(\Eccube\Entity\Csv $Csv, $entity)
    {
        // �G���e�B�e�B������v���邩�ǂ����`�F�b�N.
        if ($Csv->getEntityName() !== get_class($entity)) {
            return;
        }

        // �J���������G���e�B�e�B�ɑ��݂��邩�ǂ������`�F�b�N.
        if (!$entity->offsetExists($Csv->getFieldName())) {
            return;
        }

        // �f�[�^���擾.
        $data = $entity->offsetGet($Csv->getFieldName());

        // todo �Q�Ɛ悪�_���폜���ꂽ�P�[�X��Ή�����

        // one to one �̏ꍇ��, dtb_csv.referece_field_name�Ɣ�r��, ���v���錋�ʂ��擾����.
        if ($data instanceof \Eccube\Entity\AbstractEntity) {
            return $data->offsetGet($Csv->getReferenceFieldName());

        } elseif ($data instanceof \Doctrine\Common\Collections\ArrayCollection) {
            // one to many�̏ꍇ��, �J���}��؂�ɕϊ�����.
            $array = array();
            foreach ($data as $elem) {
                $array[] = $elem->offsetGet($Csv->getReferenceFieldName());
            }
            return implode($array, $this->config['csv_export_multidata_separator']);

        } elseif ($data instanceof \DateTime) {
            // datetime�̏ꍇ�͕�����ɕϊ�����.
            return $data->format($this->config['csv_export_date_format']);

        } else {
            // �X�J���l�̏ꍇ�͂��̂܂�.
            return $data;
        }

        return null;
    }

    /**
     * �����G���R�[�f�B���O�̕ϊ����s���R�[���o�b�N�֐���Ԃ�.
     *
     * @return \Closure
     */
    public function getConvertEncodhingCallback()
    {
        $config = $this->config;

        return function ($value) use ($config) {
            return mb_convert_encoding(
                $value, $config['csv_export_encoding'], mb_internal_encoding()
            );
        };
    }

    /**
     *
     */
    public function fopen()
    {
        if (is_null($this->fp) || $this->closed) {
            $this->fp = fopen('php://output', 'w');
        }
    }

    /**
     * @param $row
     * @param null $callback
     */
    public function fputcsv($row)
    {
        if (is_null($this->convertEncodingCallBack)) {
            $this->convertEncodingCallBack = $this->getConvertEncodhingCallback();
        }

        fputcsv($this->fp, array_map($this->convertEncodingCallBack, $row), $this->config['csv_export_separator']);
    }

    /**
     *
     */
    public function fclose()
    {
        if (!$this->closed) {
            fclose($this->fp);
            $this->closed = true;
        }
    }

    /**
     * �󒍌����p�̃N�G���r���_��Ԃ�.
     *
     * @param FormFactory $formFactory
     * @param Request $request
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getOrderQueryBuilder(FormFactory $formFactory, Request $request)
    {
        $searchForm = $formFactory
            ->createBuilder('admin_search_order')
            ->getForm();

        $searchData = array();

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);
            if ($searchForm->isValid()) {
                $searchData = $searchForm->getData();
            }
        }

        // �󒍃f�[�^�̃N�G���r���_���\�z.
        $qb = $this->orderRepository
            ->getQueryBuilderBySearchDataForAdmin($searchData);

        return $qb;
    }

    /**
     * ��������p�̃N�G���r���_��Ԃ�.
     *
     * @param FormFactory $formFactory
     * @param Request $request
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getCustomerQueryBuilder(FormFactory $formFactory, Request $request)
    {
        $searchForm = $formFactory
            ->createBuilder('admin_search_customer')
            ->getForm();

        $searchData = array();

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);
            if ($searchForm->isValid()) {
                $searchData = $searchForm->getData();
            }
        }

        // ����f�[�^�̃N�G���r���_���\�z.
        $qb = $this->customerRepository
            ->getQueryBuilderBySearchData($searchData);

        return $qb;
    }

    /**
     * ���i�����p�̃N�G���r���_��Ԃ�.
     *
     * @param FormFactory $formFactory
     * @param Request $request
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getProductQueryBuilder(FormFactory $formFactory, Request $request)
    {
        $searchForm = $formFactory
            ->createBuilder('admin_search_customer')
            ->getForm();

        $searchData = array();

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);
            if ($searchForm->isValid()) {
                $searchData = $searchForm->getData();
            }
        }

        // ���i�f�[�^�̃N�G���r���_���\�z.
        $qb = $this->productRepository
            ->getQueryBuilderBySearchDataForAdmin($searchData);

        return $qb;
    }
}