<?php
/**
 * User: Rodion Abdurakhimov
 * Mail: rodion@epages.in.ua
 * Date: 11/28/14
 * Time: 10:15
 */

namespace Epages;

use Bitrix\Highloadblock as HL;
use Bitrix\Main\DB\Exception;
use Bitrix\Main\Entity;

\CModule::IncludeModule("sale");
\CModule::IncludeModule("catalog");

/**
 * Класс для работы с многоскладовостью. Состоит из методов добавления в корзину, записи индексов корзины
 * и получения остатков товаров по складам
 * Class CMultiStore
 * @package Epages
 */
class CMultiStore 
{
    protected $elementId = 0;
    protected $elementName = "";
    protected $arStorages = Array();

    public function __construct($elementId = 0, $elementName ="", $arStorages = Array())
    {
        $this->elementId = $elementId;
        $this->elementName = $elementName;
        $this->arStorages = $arStorages;
    }

    /**
     * Возвращает сущность для работы с hl-блоком в зависимости от переданного ID hl-блока
     *
     * @param $hlblockID
     * @throws \Exception
     * @return bool
     */
    public function prepareEntity($hlblockID)
    {
        if (!\CModule::IncludeModule("highloadblock"))
        {
            throw new \Exception("Модуль highloadblock не установлен.");
        }

        $hlblock_id = $hlblockID;
        $hlblock = HL\HighloadBlockTable::getById($hlblock_id)->fetch();

        if (empty($hlblock))
        {
            throw new \Exception("404");
        }

        $entity = HL\HighloadBlockTable::compileEntity($hlblock);
        $entity_data_class = $entity->getDataClass();

        return $entity_data_class;
    }

    /**
     * Записывает в индекс зависимость ID записи в корзине от ID склада, на котором лежит товар из это записи.
     * Сделано для быстрого получения ID склада корзины по ID записи в корзине и наоборот
     *
     * @param $basketID
     * @param $storageID
     * @throws Exception
     * @codeCoverageIgnore
     */
    public function setBasketStorageIndex($basketID, $storageID)
    {
        $entity_data_class = $this->prepareEntity(HIGHLOAD_BASKET_STORAGE_INDEX);
        $result = $entity_data_class::add(
            array(
                'UF_BASKET_ID' => intval($basketID),
                'UF_STORE_ID' => intval($storageID)
            )
        );
        if ($result->isSuccess())
        {
            return $result->getId();
        }
        else
        {
            throw new Exception(implode(', ', $result->getErrors()));
        }
    }

    /**
     * Выбирает из HL-блока с индексом корзины и складов записи согласно фильтру
     *
     * @param array $arSelect
     * @param array $arOrder
     * @param array $arFilter
     * @return array
     */
    public static function getBasketStorageIndex(
        $arSelect = array("*"),
        $arOrder = array("ID" => "ASC"),
        $arFilter = array()
    )
    {
        $entity_data_class = self::prepareEntity(HIGHLOAD_BASKET_STORAGE_INDEX);
        $result = $entity_data_class::getList(
            array(
                "select" => $arSelect,
                "order" => $arOrder,
                "filter" => $arFilter
            )
        );
        if($arFields = $result->fetch())
            return $arFields;
        else
            return Array();
    }

    /**
     * На основе ID элемента, Имени элемента и его складов делает записи в корзину.
     * Добавляется отдельная запись для каждого склада товара
     * Так же записывается в индекс зависимость ID записи в корзине от ID склада,
     * на котором лежит товар из это записи
     *
     * @throws Exception
     * @codeCoverageIgnore
     */
    public function addToBasketMultiStorage()
    {
        global $USER;
        $optimalPrice = 0;

        $arPrice = \CCatalogProduct::GetOptimalPrice($this->elementId, 1, $USER->GetUserGroupArray(), 'N');
        if($arPrice['DISCOUNT_PRICE']>0)
            $optimalPrice = $arPrice['DISCOUNT_PRICE'];
        elseif($arPrice['PRICE']['PRICE']>0)
            $optimalPrice = $arPrice['PRICE']['PRICE'];

        foreach($this->arStorages as $arStorage)
        {
            $arFields = array(
                "PRODUCT_ID"             => $this->elementId,
                "PRICE"                  => $optimalPrice,
                "CURRENCY"               => "RUB",
                "QUANTITY"               => $arStorage["quantity"],
                "LID"                    => LANG,
                "NAME"                   => $this->elementName,
                "PRODUCT_PROVIDER_CLASS" => "CCustomCatalogProductProvider",
                "MODULE"                 => "catalog"
            );

            $arProps = array();

            $arProps[] = array(
                "NAME"  => "ID склада",
                "CODE"  => "STORAGE_ID",
                "VALUE" => $arStorage["storageId"]
            );

            $arProps[] = array(
                "NAME"  => "Название склада",
                "CODE"  => "STORAGE_NAME",
                "VALUE" => $this->getStorageName($arStorage["storageId"])
            );

            $arFields["PROPS"] = $arProps;

            $addRes = Add2BasketByProductID(
                $this->elementId,
                $arStorage["quantity"],
                array(),
                $arProps
            );

            if($addRes)
                $this->setBasketStorageIndex($addRes, $arStorage["storageId"]);
        }
    }

    /**
     * По ID товара возвращает массив с информацией по остаткам на складах этого товара
     *
     * @param $elementId
     * @return array
     * @codeCoverageIgnore
     */
    public static function getStoreAmount($elementId)
    {
        $arResult = Array();

        $elementId = intval($elementId);
        if($elementId <= 0)
            return $arResult;

        $rsProps = \CCatalogStore::GetList(array('TITLE' => 'ASC', 'ID' => 'ASC'), array('ACTIVE' => 'Y', "PRODUCT_ID" => $elementId), false, false, Array("*"));

        while($arProp = $rsProps->GetNext())
        {
            $arResult[] = $arProp;
        }

        return $arResult;
    }

    /**
     * Меняет PRODUCT_PROVIDER_CLASS на CCustomCatalogProductProvider для товаров, что лежат на складах
     * @param $arFields
     */
    public function OnBeforeBasketAddHandler(&$arFields)
    {
        if(count($arFields["PROPS"])>0)
        {
            $bIsMultiStorage = false;
            foreach($arFields["PROPS"] as $arProp)
            {
                if($arProp["CODE"] == "STORAGE_ID")
                {
                    $bIsMultiStorage = true;
                }
            }
            if($bIsMultiStorage)
                $arFields["PRODUCT_PROVIDER_CLASS"] = "CCustomCatalogProductProvider";
        }

        return $arFields;
    }

    /**
     * Метод копирует свойства заказа с ИД $parentOrderID в заказ с ИД $targetOrderID
     *
     * @param $parentOrderID
     * @param $targetOrderID
     * @return array
     */
    protected static function copyOrderProps($parentOrderID, $targetOrderID)
    {
        $parentOrderID = intval($parentOrderID);
        $targetOrderID = intval($targetOrderID);

        $arOrderProps = Array();

        if($parentOrderID > 0 && $targetOrderID > 0)
        {
            //получить свойства которые нужно скопировать
            $db_props = \CSaleOrderPropsValue::GetList(
                array("SORT" => "ASC"),
                array(
                    "ORDER_ID" => $parentOrderID,
                ),
                false,
                false,
                array("ORDER_PROPS_ID", "NAME", "VALUE", "CODE")
            );
            while ($arProps = $db_props->Fetch())
            {
                $arOrderProps[] = $arProps;
            }

            //скопировать свойства в заказ с ИД $targetOrderID
            if(count($arOrderProps) > 0)
            {
                foreach($arOrderProps as $arProp)
                {
                    $arProp["ORDER_ID"] = $targetOrderID;

                    \CSaleOrderPropsValue::Add($arProp);
                }
            }
        }

        return $arOrderProps;
    }

    /**
     * Метод получает корзину заказа и возвращает массив с товарами из этой корзины
     *
     * @param $orderID
     * @return array
     */
    protected static function getOrderBasketItems($orderID)
    {
        $arBasketItems = Array();
        $dbBasketItems = \CSaleBasket::GetList(
            array("NAME" => "ASC", "ID" => "ASC"),
            array("ORDER_ID" => $orderID),
            false,
            false,
            array("ID", "PRODUCT_ID", "QUANTITY")
        );
        while ($arItems = $dbBasketItems->Fetch())
        {
            $arResultBasket = Array();
            $arResultBasket["ID"] = $arItems["ID"];
            $arResultBasket["PRODUCT_ID"] = $arItems["PRODUCT_ID"];
            $arResultBasket["QUANTITY"] = $arItems["QUANTITY"];

            $db_res = \CSaleBasket::GetPropsList(
                array("SORT" => "ASC", "NAME" => "ASC"),
                array("BASKET_ID" => $arItems["ID"])
            );
            while ($ar_res = $db_res->Fetch())
                $arResultBasket["PROPS"][$ar_res["CODE"]] = $ar_res;

            $arBasketItems[] = $arResultBasket;
        }

        return $arBasketItems;
    }

    /**
     * Метод вызывается после добавления заказа. Получает корзину созданного заказа,
     * проверяет наличие в нем нескольких элементов на разных складах и разбивает заказ
     * перенося каждый элемент отдельного склада в новый заказ
     *
     * @param $ID
     * @param $arFields
     * @codeCoverageIgnore
     */
    public function OnSaleComponentOrderOneStepCompleteHandler($ID, $arFields)
    {
        \CModule::IncludeModule("sale");
        \CModule::IncludeModule("catalog");

        global $USER;

        //получить корзину заказа со свойствами
        $arBasketItems = self::getOrderBasketItems($ID);

        $newFirstOrderPrice = $arFields["PRICE"];

        foreach($arBasketItems as $arBasketItem)
        {
            //проверить наличие складов в товарах
            if(array_key_exists("STORAGE_ID", $arBasketItem["PROPS"]) && $arBasketItem !== end($arBasketItems))
            {
                //получить цену товара
                $arPrice = \CCatalogProduct::GetOptimalPrice(
                    $arBasketItem["PRODUCT_ID"],
                    $arBasketItem["QUANTITY"],
                    $USER->GetUserGroupArray(), 'N'
                );
                if($arPrice['DISCOUNT_PRICE']>0)
                    $optimalPrice = $arPrice['DISCOUNT_PRICE'];
                else
                    $optimalPrice = $arPrice['PRICE']['PRICE'];

                //собрать поля для новых заказов
                $arNewOrderFields = Array();
                foreach($arFields as $fieldCode => $fieldVal)
                {
                    if($fieldCode == "PRICE")
                        $arNewOrderFields[$fieldCode] = $optimalPrice*$arBasketItem["QUANTITY"];
                    else
                        $arNewOrderFields[$fieldCode] = $fieldVal;
                }

                //создать новый заказ
                $dividedOrderID = \CSaleOrder::Add($arNewOrderFields);
                $dividedOrderID = IntVal($dividedOrderID);

                //установить привязку к заказу
                $arOrderLink = array(
                    "ORDER_ID" => $dividedOrderID,
                    "ORDER_PROPS_ID" => 20,
                    "NAME" => "Привязка к заказу",
                    "CODE" => "ORDER_LINK",
                    "VALUE" => $ID
                );
                \CSaleOrderPropsValue::Add($arOrderLink);

                //скопировать свойства в новый заказ
                self::copyOrderProps($ID, $dividedOrderID);

                //обновить корзину и добавить в новый заказ
                \CSaleBasket::Update($arBasketItem["ID"], Array("ORDER_ID" => $dividedOrderID));

                $newFirstOrderPrice = $newFirstOrderPrice - $optimalPrice*$arBasketItem["QUANTITY"];
            }
        }
        //обновить цену старого заказа
        \CSaleOrder::Update($ID, Array("PRICE" => $newFirstOrderPrice));
    }

    /**
     * Функция возвращает название склада по его ID
     * @param $storageId
     * @return bool
     */
    public static function getStorageName($storageId)
    {
        $rsProps = \CCatalogStore::GetList(array('TITLE' => 'ASC', 'ID' => 'ASC'), array('ACTIVE' => 'Y', "ID" => intval($storageId)), false, false, Array("TITLE"));

        if($arProp = $rsProps->GetNext())
            return $arProp["TITLE"];
        else
            return false;
    }

    /**
     * Функция возвращает название склада по его ID
     * @param       $storageName
     * @param array $arSelect
     * @return bool
     */
    public static function getStorageByName($storageName, $arSelect = Array())
    {
        if(count($arSelect) == 0)
            $arSelect = Array("*");

        $rsProps = \CCatalogStore::GetList(array('TITLE' => 'ASC', 'ID' => 'ASC'), array('ACTIVE' => 'Y', "TITLE" => $storageName), false, false, $arSelect);

        if($arProp = $rsProps->GetNext())
            return $arProp;
        else
            return false;
    }

    /**
     * Метод возвращает массив ИД заказов, которые были разбиты по скаладам от заказа с ИД $orderID
     *
     * @param $orderID
     * @return array
     */
    public static function getLinkedOrders($orderID)
    {
        $arResult = Array();
        $db_props = \CSaleOrderPropsValue::GetList(
            array("SORT" => "ASC"),
            array(
                "CODE" => "ORDER_LINK",
                "VALUE" => $orderID
            )
        );
        while ($arProps = $db_props->Fetch())
        {
            $arResult[] = $arProps["ORDER_ID"];
        }

        return $arResult;
    }
}