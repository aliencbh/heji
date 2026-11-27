<?php
namespace App\Support;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class Grid {
    public static function show(Request $request,$query,array $cols,string $title,array $options=[]) {
        $keys=array_keys($cols);
        $data=$request->validate([
            'sort'=>['nullable',Rule::in($keys)],'dir'=>'nullable|in:asc,desc','q'=>'sometimes|array:'.implode(',',$keys),
            'q.*'=>'nullable|string|max:160','per'=>'nullable|integer|in:5,10,25,50,100','page'=>'nullable|integer|min:1|max:10000000',
            'columns'=>'nullable|array|min:1|max:50','columns.*'=>[Rule::in($keys),'distinct'],
            'only'=>'nullable|string|max:18000|regex:/^\d+(,\d+)*$/','hide'=>'nullable|string|max:18000|regex:/^\d+(,\d+)*$/',
            'csv'=>'sometimes|in:1',
        ]);
        foreach ($data['q']??[] as $key=>$term) {
            if ($term!==null) $query->whereRaw('CAST("'.$key.'" AS TEXT) ILIKE ?', ['%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$term).'%']);
        }
        foreach (['only'=>'whereIn','hide'=>'whereNotIn'] as $key=>$method) {
            if (!empty($data[$key])) $query->$method('id',explode(',',$data[$key]));
        }
        $order=$data['sort']??'id';
        $query->orderBy($order,$data['dir']??'desc');
        if ($order!=='id') $query->orderBy('id');
        $columns=$data['columns']??$keys;
        if ($request->boolean('csv')) {
            return response()->streamDownload(function() use ($query,$columns,$cols) {
                $file=fopen('php://output','w');
                fwrite($file,"\xEF\xBB\xBF");
                fputcsv($file,array_map(fn($k)=>$cols[$k],$columns),',','"','');
                foreach ($query->cursor() as $row) {
                    $cells=array_map(function($key) use ($row) {
                        $value=self::cell($row->$key);
                        return preg_match('/^(?:\s*[=+@-]|[\t\r\n])/u',$value) ? "'".$value : $value;
                    },$columns);
                    fputcsv($file,$cells,',','"','');
                }
                fclose($file);
            },'export-'.now()->format('Ymd-His').'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
        }
        $rows=$query->paginate($data['per']??5)->withQueryString();
        return view('grid',compact('rows','cols','columns','title','options'));
    }
    public static function cell($value): string {
        return is_bool($value) ? ($value?__('messages.yes_label'):__('messages.no_label')) : (string)$value;
    }
}