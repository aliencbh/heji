<?php
$catalog=['catalog'=>'浏览物品','catalog.create'=>'新增物品','catalog.edit'=>'编辑物品','catalog.delete'=>'删除物品','catalog.export'=>'导出物品'];
$recipe=[];
foreach (['ingredients'=>'食材','recipes'=>'食谱'] as $key=>$label) {
    foreach ([''=>'浏览','.create'=>'新增','.edit'=>'编辑','.delete'=>'删除','.export'=>'导出'] as $action=>$verb) $recipe[$key.$action]=$verb.$label;
}
return [
 'sites'=>['shop'=>'messages.site_shop','stock'=>'messages.site_stock','recipe'=>'messages.site_recipe'],
 'groups'=>[
   'shop'=>['CatalogController'=>$catalog,'ShopController'=>['orders'=>'浏览订单','orders.update'=>'处理订单','orders.export'=>'导出订单']],
   'stock'=>['CatalogController'=>$catalog,'StockController'=>['stock.adjust'=>'库存调整','stock.history'=>'库存流水','stock.history.export'=>'导出流水','purchase.create'=>'提交采购','purchase.manage'=>'浏览采购单','purchase.manage.update'=>'处理采购单','purchase.manage.export'=>'导出采购单']],
   'recipe'=>['CatalogController'=>$recipe,'CalendarController'=>['calendar'=>'浏览日历','calendar.edit'=>'排列、顺延及交换','calendar.settings'=>'星期设置','restock'=>'浏览补货清单','restock.update'=>'修改现存量']],
 ],
 'meats'=>['鱼','虾','sotong','鹅','鸡','江鱼仔','鸭','猪'],
 'vegetables'=>['蛋','豆腐','豆干','菇','花生','莲藕','马铃薯'],
 'extras'=>['部队锅','饭','面','西餐','粥','面包','小吃'],
 'roles'=>['开发员','管理员','经理','推广','人事','财务','仓库','物流','清洁','保安'],
];