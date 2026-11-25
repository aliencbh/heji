GRANT USAGE ON SCHEMA audit TO heji_app;
GRANT SELECT,DELETE ON ALL TABLES IN SCHEMA audit TO heji_app;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA audit TO heji_app;
REVOKE ALL ON public.migrations FROM heji_app;
GRANT SELECT ON public.migrations TO heji_app;
