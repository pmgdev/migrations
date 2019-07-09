CREATE EXTENSION IF NOT EXISTS plpgsql WITH SCHEMA pg_catalog;

CREATE SCHEMA system;

CREATE TABLE system.deployed_scripts
(
  id serial,
  filename text NOT NULL,
  inserted_datetime timestamp with time zone NOT NULL DEFAULT now(),
  CONSTRAINT deployed_scripts_pkey PRIMARY KEY (id)
);

CREATE UNIQUE INDEX deployed_scripts_filename_ukey ON system.deployed_scripts (lower(filename) ASC NULLS LAST);

---

CREATE FUNCTION system.deploy_script(in_filename text, in_dependence text[] DEFAULT array[]::text[])
  RETURNS void AS
$BODY$
DECLARE
  dependence record;
BEGIN
  IF (EXISTS(SELECT 1 FROM system.deployed_scripts WHERE lower(filename) = lower(in_filename))) THEN
    RAISE EXCEPTION USING MESSAGE = format('Script "%s" is already deployed.', in_filename);
  END IF;

  IF in_dependence IS NOT NULL THEN
    FOR dependence IN SELECT unnest(in_dependence) AS filename LOOP
      IF (NOT EXISTS(SELECT 1 FROM system.deployed_scripts WHERE lower(filename) = lower(dependence.filename))) THEN
        RAISE EXCEPTION USING MESSAGE = format('Script "%s" needs script "%s" deployed.', in_filename, dependence.filename);
      END IF;
    END LOOP;
  END IF;

  INSERT INTO system.deployed_scripts(filename) VALUES(in_filename);
END;
$BODY$
  LANGUAGE plpgsql VOLATILE;

---

CREATE FUNCTION system.list_deployed_scripts()
  RETURNS TABLE(script_filename text) AS
$BODY$
  SELECT filename FROM system.deployed_scripts;
$BODY$
  LANGUAGE sql STABLE;

---

CREATE FUNCTION system.list_deployed_scripts(in_filenames text)
  RETURNS text AS
$BODY$
  SELECT string_agg(filenames.filename, ',' ORDER BY filenames.filename) FROM (
    SELECT unnest(string_to_array(trim(TRAILING ',' FROM in_filenames), ','))
    EXCEPT
    SELECT filename FROM system.deployed_scripts
  ) AS filenames(filename);
$BODY$
  LANGUAGE sql STABLE;

---

CREATE FUNCTION system.show_deploy_warning(in_message text)
  RETURNS void AS
$BODY$
BEGIN
  RAISE EXCEPTION USING MESSAGE = in_message, HINT = 'Comment this line to continue.';
END
$BODY$
  LANGUAGE plpgsql VOLATILE;
