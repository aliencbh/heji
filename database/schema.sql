CREATE SCHEMA audit;
CREATE TABLE users (id bigserial PRIMARY KEY,name varchar(100) NOT NULL,email varchar(254) UNIQUE NOT NULL,password varchar(255) NOT NULL,developer boolean NOT NULL DEFAULT false,remember_token varchar(100),created_at timestamp,updated_at timestamp);
CREATE TABLE roles (id bigserial PRIMARY KEY,name varchar(64) UNIQUE NOT NULL);
CREATE TABLE memberships (id bigserial PRIMARY KEY,user_id bigint NOT NULL REFERENCES users,site text NOT NULL CHECK(site IN ('shop','stock','recipe')),role_id bigint NOT NULL REFERENCES roles ON DELETE CASCADE,UNIQUE(user_id,site));
CREATE TABLE grants (id bigserial PRIMARY KEY,role_id bigint NOT NULL REFERENCES roles ON DELETE CASCADE,site text NOT NULL,page text NOT NULL,UNIQUE(role_id,site,page));
CREATE TABLE products (id bigserial PRIMARY KEY,name varchar(120) NOT NULL,sku varchar(48) UNIQUE NOT NULL,price numeric(12,2) NOT NULL CHECK(price>=0),stock integer NOT NULL DEFAULT 0 CHECK(stock>=0),active boolean NOT NULL DEFAULT true,photo varchar(64),description text);
CREATE TABLE orders (id bigserial PRIMARY KEY,user_id bigint NOT NULL REFERENCES users,nonce uuid NOT NULL UNIQUE,status text NOT NULL DEFAULT '待处理' CHECK(status IN ('待处理','已完成','已取消')),total numeric(20,2) NOT NULL CHECK(total>=0),created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE order_lines (id bigserial PRIMARY KEY,order_id bigint NOT NULL REFERENCES orders,product_id bigint NOT NULL REFERENCES products,name text NOT NULL,price numeric(12,2) NOT NULL,quantity int NOT NULL CHECK(quantity>0));
CREATE TABLE purchases (id bigserial PRIMARY KEY,client_ref uuid NOT NULL,user_id bigint NOT NULL REFERENCES users,product_id bigint NOT NULL REFERENCES products,quantity integer NOT NULL CHECK(quantity>0),note text,status text NOT NULL DEFAULT '待收货' CHECK(status IN ('待收货','已收货','已取消')),created_at timestamptz NOT NULL DEFAULT now());
ALTER TABLE purchases ADD UNIQUE (user_id,client_ref);
CREATE TABLE movements (id bigserial PRIMARY KEY,product_id bigint NOT NULL REFERENCES products,delta integer NOT NULL CHECK(delta<>0),reason varchar(180) NOT NULL,user_id bigint NOT NULL REFERENCES users,created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE ingredients (id bigserial PRIMARY KEY,name varchar(100) UNIQUE NOT NULL,unit varchar(16) NOT NULL,notes text,weekly boolean NOT NULL DEFAULT false,storable boolean NOT NULL DEFAULT false,marinate boolean NOT NULL DEFAULT false,vacuum boolean NOT NULL DEFAULT true,storage text NOT NULL CHECK(storage IN ('通风处','冷藏','冷冻')),reserve numeric(12,3) NOT NULL DEFAULT 0 CHECK(reserve>=0),remaining numeric(12,3) NOT NULL DEFAULT 0 CHECK(remaining>=0));
CREATE TABLE recipes (id bigserial PRIMARY KEY,name varchar(120) UNIQUE NOT NULL,kind text NOT NULL CHECK(kind IN ('荤菜','素菜','汤','周末其他')),family varchar(32) NOT NULL,method text NOT NULL,adjustment text,tasty boolean NOT NULL DEFAULT true,cooked boolean NOT NULL DEFAULT false);
CREATE TABLE recipe_items (id bigserial PRIMARY KEY,recipe_id bigint NOT NULL REFERENCES recipes ON DELETE CASCADE,ingredient_id bigint NOT NULL REFERENCES ingredients,quantity numeric(12,3) NOT NULL CHECK(quantity>0),UNIQUE(recipe_id,ingredient_id));
CREATE TABLE settings (id bigserial PRIMARY KEY,key varchar(64) UNIQUE NOT NULL,value text NOT NULL);
CREATE TABLE meal_days (id bigserial PRIMARY KEY,day date UNIQUE NOT NULL,skipped boolean NOT NULL DEFAULT false);
CREATE TABLE meals (id bigserial PRIMARY KEY,day date REFERENCES meal_days(day) ON DELETE CASCADE NOT NULL,slot text NOT NULL,recipe_id bigint NOT NULL REFERENCES recipes,UNIQUE(day,slot));
CREATE TABLE backups (id bigserial PRIMARY KEY,name varchar(32) UNIQUE NOT NULL,label varchar(120),size bigint NOT NULL DEFAULT 0,created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE log_entries (id bigserial PRIMARY KEY,category varchar(40) NOT NULL,scope varchar(64) NOT NULL,message text NOT NULL,created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE log_offsets (id bigserial PRIMARY KEY,path text UNIQUE NOT NULL,inode bigint NOT NULL,position bigint NOT NULL);
CREATE TABLE security_events (id bigserial PRIMARY KEY,actor_id bigint,event varchar(96) NOT NULL,details text,created_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX meal_recipe ON meals(recipe_id);
CREATE INDEX movement_product ON movements(product_id);
CREATE INDEX orders_user ON orders(user_id);
CREATE INDEX purchases_user ON purchases(user_id);
CREATE INDEX log_scope ON log_entries(category,scope,id);
CREATE FUNCTION audit.snapshot() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE prev jsonb; following jsonb; who bigint;
BEGIN
 who:=nullif(current_setting('heji.actor',true),'')::bigint;
 IF TG_OP <> 'INSERT' THEN prev:=to_jsonb(OLD)-ARRAY['password','remember_token']; END IF;
 IF TG_OP <> 'DELETE' THEN following:=to_jsonb(NEW)-ARRAY['password','remember_token']; END IF;
 IF TG_TABLE_NAME='users' AND TG_OP='INSERT' AND who IS NULL THEN who:=NEW.id; END IF;
 EXECUTE format('INSERT INTO audit.%I(actor_id,action,record_id,old_data,new_data) VALUES ($1,$2,$3,$4,$5)',TG_TABLE_NAME)
 USING who,TG_OP,coalesce(following->>'id',prev->>'id'),prev,following;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
DO $$
DECLARE t text;
BEGIN
 FOR t IN SELECT tablename FROM pg_tables WHERE schemaname='public' LOOP
 EXECUTE format('CREATE TABLE audit.%I (id bigserial PRIMARY KEY,actor_id bigint,at timestamptz NOT NULL DEFAULT clock_timestamp(),action text NOT NULL,record_id text,old_data jsonb,new_data jsonb)',t);
 EXECUTE format('CREATE TRIGGER heji_snapshot BEFORE INSERT OR UPDATE OR DELETE ON public.%I FOR EACH ROW EXECUTE FUNCTION audit.snapshot()',t,t);
 END LOOP;
END $$;
INSERT INTO settings(key,value) VALUES ('first_day','1'),('weekend','[6,7]'),('backup_minutes','1440');
INSERT INTO roles(name) VALUES ('开发员'),('管理员'),('经理'),('推广'),('人事'),('财务'),('仓库'),('物流'),('清洁'),('保安');