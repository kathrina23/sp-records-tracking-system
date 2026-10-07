ALTER TABLE city_officials
    ADD COLUMN district ENUM('District 1', 'District 2', 'Ex Officio') NULL AFTER position;
