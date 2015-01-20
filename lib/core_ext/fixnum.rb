class ::Fixnum
  def to_hex(byte_count)
    to_s(16).rjust(byte_count * 2, '0')
  end
end
